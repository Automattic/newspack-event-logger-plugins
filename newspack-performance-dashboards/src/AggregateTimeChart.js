/* global requestAnimationFrame, cancelAnimationFrame */
/**
 * Aggregate Time Chart Component
 *
 * D3-based chart with dropdown-controlled metric and breakdown dimensions.
 * Stacked bars for volume/cumulative metrics, line chart for avg response time.
 */

import { useEffect, useRef, useCallback, useMemo } from '@wordpress/element';
import * as d3 from 'd3';
import { STATUS_COLORS } from './shared/utils/formatUtils';

// Color palette for dimensional breakdowns (d3.schemeTableau10).
const DIM_PALETTE = [
	'#4e79a7',
	'#f28e2b',
	'#e15759',
	'#76b7b2',
	'#59a14f',
	'#edc948',
	'#b07aa1',
	'#ff9da7',
	'#9c755f',
	'#bab0ac',
	'#6b46c1',
	'#2ca02c',
	'#d62728',
	'#1f77b4',
	'#ff7f0e',
	'#8c564b',
	'#7f7f7f',
	'#bcbd22',
	'#17becf',
	'#aec7e8',
];

/**
 * Format a value in seconds to human-readable form.
 * e.g. 0.25 → "250ms", 45 → "45s", 1500 → "1.5Ks"
 *
 * @param {number} seconds Value in seconds.
 * @return {string} Formatted string.
 */
const formatSeconds = ( seconds ) => {
	if ( seconds === 0 ) {
		return '0s';
	}
	if ( seconds < 1 ) {
		return `${ Math.round( seconds * 1000 ) }ms`;
	}
	if ( seconds >= 1000 ) {
		const ks = seconds / 1000;
		return ks === Math.floor( ks ) ? `${ ks }Ks` : `${ ks.toFixed( 1 ) }Ks`;
	}
	return seconds < 10
		? `${ seconds.toFixed( 1 ) }s`
		: `${ Math.round( seconds ) }s`;
};

// Retention window in seconds from config, default 24 hours.
const RETENTION_SECONDS =
	Number( window.eventLoggerDashboards?.retentionSeconds ) || 86400;
const BUCKET_MINUTES = 5;
const BUCKET_MS = BUCKET_MINUTES * 60 * 1000;
const NUM_BUCKETS = Math.ceil( RETENTION_SECONDS / ( BUCKET_MINUTES * 60 ) );

/**
 * Build time slots (5-minute buckets over the retention window).
 *
 * @return {Array} Array of { date, bucketKey } objects.
 */
const buildTimeSlots = () => {
	const now = new Date();
	const slots = [];
	for ( let i = NUM_BUCKETS - 1; i >= 0; i-- ) {
		const date = new Date( now.getTime() - i * BUCKET_MS );
		date.setMinutes( Math.floor( date.getMinutes() / 5 ) * 5, 0, 0 );
		const bucketKey = [
			date.getUTCFullYear(),
			String( date.getUTCMonth() + 1 ).padStart( 2, '0' ),
			String( date.getUTCDate() ).padStart( 2, '0' ),
			String( date.getUTCHours() ).padStart( 2, '0' ),
			String( Math.floor( date.getUTCMinutes() / 5 ) * 5 ).padStart(
				2,
				'0'
			),
		].join( '-' );
		slots.push( { date, bucketKey } );
	}
	return slots;
};

/**
 * Aggregate Time Chart component.
 *
 * @param {Object}      props               Component props.
 * @param {Object}      props.data          Status code time series (aggregate_time_series).
 * @param {Object|null} props.breakdownData Dimensional time series or null.
 * @param {string}      props.metric        'volume' | 'avg' | 'cumulative'.
 * @param {string}      props.breakdown     'status' | 'method' | 'server' | etc.
 * @param {string}      props.serverFilter  Server name to filter by, or '' for all.
 * @return {JSX.Element} Rendered component.
 */
export default function AggregateTimeChart( {
	data,
	breakdownData,
	metric = 'volume',
	breakdown = 'status',
	serverFilter = '',
} ) {
	const containerRef = useRef( null );
	const tooltipRef = useRef( null );
	const lastMouseXRef = useRef( null );

	// Pre-compute chart data.
	const chartState = useMemo( () => {
		if ( ! data ) {
			return { chartData: [], keys: [], colorMap: {}, isLine: false };
		}

		const isLine = metric === 'avg' || metric === 'memory';
		const effectiveBreakdown = breakdownData;
		const slots = buildTimeSlots();

		if ( ! effectiveBreakdown ) {
			// No dimensional data — use base data for a single-series "Total" chart.
			// Base data format: { bucket_key: { count, sum_ms } }.
			const key = 'Total';
			const chartData = slots.map( ( { date, bucketKey } ) => {
				const b = data[ bucketKey ];
				const row = { date };
				if ( metric === 'memory' ) {
					row[ key ] =
						b && b.count > 0 ? ( b.sum_peak_mb || 0 ) / b.count : 0;
				} else if ( metric === 'avg' ) {
					row[ key ] =
						b && b.count > 0 ? Math.round( b.sum_ms / b.count ) : 0;
				} else if ( metric === 'cumulative' ) {
					row[ key ] = b ? b.sum_ms / 1000 : 0;
				} else {
					row[ key ] = b ? b.count : 0;
				}
				return row;
			} );
			return {
				chartData,
				keys: [ key ],
				colorMap: { [ key ]: DIM_PALETTE[ 0 ] },
				isLine,
			};
		}

		// Dimensional breakdown (all breakdowns including status).
		// Collect all unique dimension values.
		const valueSet = new Set();
		Object.values( breakdownData ).forEach( ( bucket ) => {
			Object.keys( bucket ).forEach( ( v ) => valueSet.add( v ) );
		} );
		const dimValues = Array.from( valueSet );

		// Build color map: use STATUS_COLORS for status breakdown, DIM_PALETTE otherwise.
		const colorMap = {};
		dimValues.forEach( ( v, i ) => {
			colorMap[ v ] =
				breakdown === 'status' && STATUS_COLORS[ v ]
					? STATUS_COLORS[ v ]
					: DIM_PALETTE[ i % DIM_PALETTE.length ];
		} );

		const chartData = slots.map( ( { date, bucketKey } ) => {
			const bucket = breakdownData[ bucketKey ] || {};
			const row = { date };

			if ( isLine ) {
				// For avg/memory metrics, compute average per dimension value (for lines).
				dimValues.forEach( ( v ) => {
					const s = bucket[ v ];
					if ( metric === 'memory' ) {
						row[ v ] = s && s.c > 0 ? ( s.m || 0 ) / s.c : 0;
					} else {
						row[ v ] = s && s.c > 0 ? Math.round( s.s / s.c ) : 0;
					}
				} );
			} else {
				dimValues.forEach( ( v ) => {
					const s = bucket[ v ];
					if ( metric === 'volume' ) {
						row[ v ] = s ? s.c : 0;
					} else {
						// cumulative: ms to seconds.
						row[ v ] = s ? s.s / 1000 : 0;
					}
				} );
			}
			return row;
		} );

		return { chartData, keys: dimValues, colorMap, isLine };
	}, [ data, breakdownData, metric, breakdown ] );

	/**
	 * Render the chart.
	 */
	const renderChart = useCallback( () => {
		if ( ! containerRef.current || chartState.chartData.length === 0 ) {
			return;
		}

		const { chartData, keys, colorMap, isLine } = chartState;

		// Clear previous chart.
		d3.select( containerRef.current ).selectAll( '*' ).remove();

		// Dimensions.
		const margin = { top: 20, right: 160, bottom: 65, left: 60 };
		const width =
			( containerRef.current.clientWidth || 800 ) -
			margin.left -
			margin.right;
		const height = 280 - margin.top - margin.bottom;

		// Create SVG.
		const svg = d3
			.select( containerRef.current )
			.append( 'svg' )
			.attr( 'width', width + margin.left + margin.right )
			.attr( 'height', height + margin.top + margin.bottom )
			.append( 'g' )
			.attr( 'transform', `translate(${ margin.left },${ margin.top })` );

		// X scale.
		const x = d3
			.scaleTime()
			.domain( d3.extent( chartData, ( d ) => d.date ) )
			.range( [ 0, width ] );

		// X axis.
		svg.append( 'g' )
			.attr( 'transform', `translate(0,${ height })` )
			.call(
				d3
					.axisBottom( x )
					.ticks( Math.min( chartData.length, 8 ) )
					.tickFormat( ( d ) => {
						const month = d.getMonth() + 1;
						const day = d.getDate();
						const hour = d.getHours();
						const min = String( d.getMinutes() ).padStart( 2, '0' );
						return `${ month }/${ day } ${ hour }:${ min }`;
					} )
			)
			.selectAll( 'text' )
			.attr( 'transform', 'rotate(-45)' )
			.style( 'text-anchor', 'end' );

		if ( isLine ) {
			// LINE CHART for avg response time.
			const lineKeys = keys;

			// Y scale.
			let yMax = 0;
			chartData.forEach( ( d ) => {
				lineKeys.forEach( ( k ) => {
					if ( ( d[ k ] || 0 ) > yMax ) {
						yMax = d[ k ];
					}
				} );
			} );
			yMax = ( yMax || 100 ) * 1.2;
			const y = d3
				.scaleLinear()
				.domain( [ 0, yMax ] )
				.range( [ height, 0 ] );

			// Y axis.
			svg.append( 'g' ).call(
				d3
					.axisLeft( y )
					.ticks( 5 )
					.tickFormat( ( d ) =>
						metric === 'memory'
							? `${ Number( d.toFixed( 1 ) ) }MB`
							: `${ d }ms`
					)
			);

			// Y axis label.
			svg.append( 'text' )
				.attr( 'transform', 'rotate(-90)' )
				.attr( 'y', 0 - margin.left )
				.attr( 'x', 0 - height / 2 )
				.attr( 'dy', '1em' )
				.style( 'text-anchor', 'middle' )
				.style( 'font-size', '12px' )
				.text(
					metric === 'memory'
						? 'Avg Peak Memory (MB)'
						: 'Avg Response Time (ms)'
				);

			// Draw overlaid areas per key.
			const area = d3
				.area()
				.x( ( d ) => x( d.date ) )
				.y0( height )
				.y1( ( d ) => y( d.value ) )
				.curve( d3.curveMonotoneX );

			lineKeys.forEach( ( key ) => {
				const areaColor = colorMap[ key ] || DIM_PALETTE[ 0 ];
				const areaData = chartData.map( ( d ) => ( {
					date: d.date,
					value: d[ key ] || 0,
				} ) );

				svg.append( 'path' )
					.datum( areaData )
					.attr( 'fill', areaColor )
					.attr( 'fill-opacity', 0.5 )
					.attr( 'stroke', areaColor )
					.attr( 'stroke-width', 1 )
					.attr( 'd', area );
			} );

			// Highlight bar + HTML tooltip below chart.
			const ttBucketW = width / chartData.length;
			const ttHighlight = svg
				.append( 'rect' )
				.attr( 'y', 0 )
				.attr( 'height', height )
				.attr( 'width', ttBucketW )
				.attr( 'fill', 'rgba(255,255,255,0.1)' )
				.attr( 'stroke', 'rgba(255,255,255,0.3)' )
				.attr( 'stroke-width', 1 )
				.attr( 'opacity', 0 );

			const ttEl = tooltipRef.current;
			const ttDates = chartData.map( ( d ) => d.date );
			const ttBisect = d3.bisector( ( d ) => d ).left;
			const ttFmt =
				metric === 'memory'
					? ( v ) => `${ v.toFixed( 1 ) }MB`
					: ( v ) => `${ v }ms`;

			const showTt = ( mx ) => {
				const dateAtMouse = x.invert( mx );
				const i1 = Math.min(
					ttBisect( ttDates, dateAtMouse ),
					ttDates.length - 1
				);
				const i0 = Math.max( 0, i1 - 1 );
				const i =
					dateAtMouse - ttDates[ i0 ] < ttDates[ i1 ] - dateAtMouse
						? i0
						: i1;
				const xPos = x( ttDates[ i ] );

				ttHighlight
					.attr( 'x', xPos - ttBucketW / 2 )
					.attr( 'opacity', 1 );

				ttEl.textContent = '';
				const header = document.createElement( 'strong' );
				header.textContent = ttDates[ i ].toLocaleTimeString();
				ttEl.appendChild( header );
				const ttEntries = lineKeys
					.map( ( k ) => ( { k, val: chartData[ i ][ k ] || 0 } ) )
					.filter( ( e ) => e.val > 0 )
					.sort( ( a, b ) => b.val - a.val )
					.slice( 0, 10 );
				ttEntries.forEach( ( e ) => {
					ttEl.appendChild( document.createElement( 'br' ) );
					ttEl.appendChild(
						document.createTextNode(
							`${ e.k }: ${ ttFmt( e.val ) }`
						)
					);
				} );
				ttEl.style.display = 'block';
				ttEl.style.left = `${ margin.left + xPos }px`;
				const ttParent = containerRef.current.parentElement;
				ttEl.style.top = `${ ttParent.clientHeight }px`;
				const ttRect = ttEl.getBoundingClientRect();
				if ( ttRect.bottom > window.innerHeight ) {
					ttEl.style.top = `-${ ttEl.offsetHeight + 4 }px`;
				}
				if ( ttRect.right > window.innerWidth ) {
					ttEl.style.left = `${
						margin.left + xPos - ttEl.offsetWidth
					}px`;
				}
			};

			let ttRafId = null;
			function hideTt() {
				if ( ttRafId ) {
					cancelAnimationFrame( ttRafId );
					ttRafId = null;
				}
				lastMouseXRef.current = null;
				ttEl.style.display = 'none';
				ttHighlight.attr( 'opacity', 0 );
			}

			svg.append( 'rect' )
				.attr( 'width', width )
				.attr( 'height', height )
				.attr( 'fill', 'none' )
				.attr( 'pointer-events', 'all' )
				.on( 'mousemove', ( event ) => {
					const [ mx ] = d3.pointer( event, svg.node() );
					lastMouseXRef.current = mx;
					if ( ttRafId ) {
						cancelAnimationFrame( ttRafId );
					}
					ttRafId = requestAnimationFrame( () => {
						ttRafId = null;
						if ( lastMouseXRef.current === null ) {
							return;
						}
						showTt( lastMouseXRef.current );
					} );
				} )
				.on( 'mouseleave', hideTt );

			if ( lastMouseXRef.current !== null ) {
				showTt( lastMouseXRef.current );
			}

			// Legend.
			const legendKeys = lineKeys.map( ( k ) => ( {
				type: 'rect',
				color: colorMap[ k ] || DIM_PALETTE[ 0 ],
				label: k,
			} ) );
			drawLegend( svg, legendKeys, width );
		} else {
			// STACKED AREA CHART for volume/cumulative.
			const stack = d3.stack().keys( keys );
			const stackedData = stack( chartData );

			// Y scale.
			const yMax =
				d3.max( chartData, ( d ) =>
					keys.reduce( ( sum, k ) => sum + ( d[ k ] || 0 ), 0 )
				) * 1.1 || 10;
			const y = d3
				.scaleLinear()
				.domain( [ 0, yMax ] )
				.range( [ height, 0 ] );

			// Y axis.
			const yFormat =
				metric === 'cumulative' ? formatSeconds : d3.format( 'd' );
			svg.append( 'g' ).call(
				d3.axisLeft( y ).ticks( 5 ).tickFormat( yFormat )
			);

			// Y axis label.
			svg.append( 'text' )
				.attr( 'transform', 'rotate(-90)' )
				.attr( 'y', 0 - margin.left )
				.attr( 'x', 0 - height / 2 )
				.attr( 'dy', '1em' )
				.style( 'text-anchor', 'middle' )
				.style( 'font-size', '12px' )
				.text(
					metric === 'cumulative' ? 'Cumulative Time' : 'Requests'
				);

			// Draw stacked areas.
			const stackArea = d3
				.area()
				.x( ( d ) => x( d.data.date ) )
				.y0( ( d ) => y( d[ 0 ] ) )
				.y1( ( d ) => y( d[ 1 ] ) )
				.curve( d3.curveMonotoneX );

			svg.selectAll( '.layer' )
				.data( stackedData )
				.enter()
				.append( 'path' )
				.attr( 'class', 'layer' )
				.attr( 'fill', ( d ) => colorMap[ d.key ] || '#999' )
				.attr( 'fill-opacity', 0.7 )
				.attr( 'stroke', ( d ) => colorMap[ d.key ] || '#999' )
				.attr( 'stroke-width', 0.5 )
				.attr( 'd', stackArea );

			// Tooltip for stacked areas.
			const saBucketW = width / chartData.length;
			const saHighlight = svg
				.append( 'rect' )
				.attr( 'y', 0 )
				.attr( 'height', height )
				.attr( 'width', saBucketW )
				.attr( 'fill', 'rgba(255,255,255,0.1)' )
				.attr( 'stroke', 'rgba(255,255,255,0.3)' )
				.attr( 'stroke-width', 1 )
				.attr( 'opacity', 0 );

			const saEl = tooltipRef.current;
			const saDates = chartData.map( ( d ) => d.date );
			const saBisect = d3.bisector( ( d ) => d ).left;
			const saFmt =
				metric === 'cumulative'
					? ( v ) => formatSeconds( v )
					: ( v ) => String( Math.round( v ) );

			const showSaTt = ( mx ) => {
				const dateAtMouse = x.invert( mx );
				const si1 = Math.min(
					saBisect( saDates, dateAtMouse ),
					saDates.length - 1
				);
				const si0 = Math.max( 0, si1 - 1 );
				const si =
					dateAtMouse - saDates[ si0 ] < saDates[ si1 ] - dateAtMouse
						? si0
						: si1;
				const saXPos = x( saDates[ si ] );

				saHighlight
					.attr( 'x', saXPos - saBucketW / 2 )
					.attr( 'opacity', 1 );

				saEl.textContent = '';
				const header = document.createElement( 'strong' );
				header.textContent = saDates[ si ].toLocaleTimeString();
				saEl.appendChild( header );
				const total = keys.reduce(
					( sum, k ) => sum + ( chartData[ si ][ k ] || 0 ),
					0
				);
				saEl.appendChild( document.createElement( 'br' ) );
				saEl.appendChild(
					document.createTextNode( `Total: ${ saFmt( total ) }` )
				);
				const saEntries = keys
					.map( ( k ) => ( { k, val: chartData[ si ][ k ] || 0 } ) )
					.filter( ( e ) => e.val > 0 )
					.sort( ( a, b ) => b.val - a.val )
					.slice( 0, 10 );
				saEntries.forEach( ( e ) => {
					saEl.appendChild( document.createElement( 'br' ) );
					saEl.appendChild(
						document.createTextNode(
							`${ e.k }: ${ saFmt( e.val ) }`
						)
					);
				} );
				saEl.style.display = 'block';
				saEl.style.left = `${ margin.left + saXPos }px`;
				const saParent = containerRef.current.parentElement;
				saEl.style.top = `${ saParent.clientHeight }px`;
				const saRect = saEl.getBoundingClientRect();
				if ( saRect.bottom > window.innerHeight ) {
					saEl.style.top = `-${ saEl.offsetHeight + 4 }px`;
				}
				if ( saRect.right > window.innerWidth ) {
					saEl.style.left = `${
						margin.left + saXPos - saEl.offsetWidth
					}px`;
				}
			};

			let saRafId = null;
			function hideSaTt() {
				if ( saRafId ) {
					cancelAnimationFrame( saRafId );
					saRafId = null;
				}
				lastMouseXRef.current = null;
				saEl.style.display = 'none';
				saHighlight.attr( 'opacity', 0 );
			}

			svg.append( 'rect' )
				.attr( 'width', width )
				.attr( 'height', height )
				.attr( 'fill', 'none' )
				.attr( 'pointer-events', 'all' )
				.on( 'mousemove', ( event ) => {
					const [ mx ] = d3.pointer( event );
					lastMouseXRef.current = mx;
					if ( saRafId ) {
						cancelAnimationFrame( saRafId );
					}
					saRafId = requestAnimationFrame( () => {
						saRafId = null;
						if ( lastMouseXRef.current === null ) {
							return;
						}
						showSaTt( lastMouseXRef.current );
					} );
				} )
				.on( 'mouseleave', hideSaTt );

			if ( lastMouseXRef.current !== null ) {
				showSaTt( lastMouseXRef.current );
			}

			// Legend.
			const legendKeys = keys.map( ( k ) => ( {
				type: 'rect',
				color: colorMap[ k ] || '#999',
				label: k,
			} ) );
			drawLegend( svg, legendKeys, width );
		}
	}, [ chartState, metric ] );

	// Initial render and data change.
	useEffect( () => {
		renderChart();
	}, [ renderChart ] );

	// Handle resize.
	useEffect( () => {
		const handleResize = () => renderChart();
		window.addEventListener( 'resize', handleResize );
		return () => window.removeEventListener( 'resize', handleResize );
	}, [ renderChart ] );

	// Hide tooltip on scroll — chart moves away from cursor without mouseleave.
	useEffect( () => {
		const el = containerRef.current;
		if ( ! el ) {
			return;
		}
		const scrollParent =
			el.closest( '.components-modal__content' ) || window;
		const hideOnScroll = () => {
			lastMouseXRef.current = null;
			if ( tooltipRef.current ) {
				tooltipRef.current.style.display = 'none';
			}
		};
		scrollParent.addEventListener( 'scroll', hideOnScroll, {
			passive: true,
		} );
		return () => {
			scrollParent.removeEventListener( 'scroll', hideOnScroll );
		};
	}, [] );

	if ( ! data || Object.keys( data ).length === 0 ) {
		return null;
	}

	const metricLabels = {
		volume: 'Request Volume',
		avg: 'Avg Response Time',
		cumulative: 'Cumulative Response Time',
		memory: 'Avg Peak Memory',
	};

	const titleSuffix = serverFilter ? ` — ${ serverFilter }` : '';
	const retentionLabel =
		RETENTION_SECONDS >= 3600
			? `${ Math.round( RETENTION_SECONDS / 3600 ) } Hours`
			: `${ Math.round( RETENTION_SECONDS / 60 ) } Minutes`;

	return (
		<div
			className="event-logger-aggregate-time-chart"
			style={ { position: 'relative' } }
		>
			<h3>
				{ `${
					metricLabels[ metric ] || 'Chart'
				} (Last ${ retentionLabel })` }
				{ titleSuffix }
			</h3>
			<div
				ref={ containerRef }
				style={ { width: '100%', minHeight: '284px' } }
			/>
			<div
				ref={ tooltipRef }
				style={ {
					display: 'none',
					position: 'absolute',
					background: 'rgba(0,0,0,0.85)',
					color: '#fff',
					padding: '6px 10px',
					borderRadius: '4px',
					fontSize: '11px',
					fontFamily: 'monospace',
					pointerEvents: 'none',
					whiteSpace: 'nowrap',
					zIndex: 10,
				} }
			/>
		</div>
	);
}

/**
 * Draw vertical legend on right side of chart.
 *
 * @param {Object} svg   D3 SVG selection (inner g with margin transform).
 * @param {Array}  items Legend items with type, color, label.
 * @param {number} width Chart inner width.
 */
function drawLegend( svg, items, width ) {
	const legend = svg
		.append( 'g' )
		.attr( 'transform', `translate(${ width + 10 }, 0)` );

	items.forEach( ( item, i ) => {
		const ly = i * 16;
		legend
			.append( 'rect' )
			.attr( 'x', 0 )
			.attr( 'y', ly )
			.attr( 'width', 10 )
			.attr( 'height', 10 )
			.attr( 'fill', item.color );

		// Truncate long labels.
		const label =
			item.label.length > 20
				? item.label.slice( 0, 18 ) + '...'
				: item.label;

		legend
			.append( 'text' )
			.attr( 'x', 14 )
			.attr( 'y', ly + 9 )
			.text( label )
			.style( 'font-size', '11px' )
			.style( 'fill', '#888' );
	} );
}
