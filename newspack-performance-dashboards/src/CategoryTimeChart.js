/* global requestAnimationFrame, cancelAnimationFrame */
/**
 * Category Time Chart Component
 *
 * D3-based overlaid area chart showing profile category breakdowns over time.
 * Two modes: "time" (seconds per second) and "count" (events per second).
 */

import { useEffect, useRef, useMemo } from '@wordpress/element';
import * as d3 from 'd3';

const PALETTE = [
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

const RETENTION_SECONDS =
	Number( window.eventLoggerDashboards?.retentionSeconds ) || 86400;
const BUCKET_MINUTES = 5;
const BUCKET_SECONDS = BUCKET_MINUTES * 60;
const BUCKET_MS = BUCKET_SECONDS * 1000;
const NUM_BUCKETS = Math.ceil( RETENTION_SECONDS / BUCKET_SECONDS );

const MARGIN = { top: 10, right: 160, bottom: 65, left: 60 };

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
			String( date.getUTCMinutes() ).padStart( 2, '0' ),
		].join( '-' );
		slots.push( { date, bucketKey } );
	}
	return slots;
};

const formatYValue = ( val, mode ) => {
	if ( val === 0 ) {
		return '0';
	}
	if ( mode === 'time' ) {
		if ( val < 0.001 ) {
			return `${ ( val * 1000000 ).toFixed( 0 ) }\u00B5s/s`;
		}
		if ( val < 1 ) {
			return `${ ( val * 1000 ).toFixed( 0 ) }ms/s`;
		}
		return `${ val.toFixed( 1 ) }s/s`;
	}
	if ( mode === 'average' ) {
		if ( val < 1 ) {
			return `${ ( val * 1000 ).toFixed( 0 ) }\u00B5s`;
		}
		if ( val >= 1000 ) {
			return `${ ( val / 1000 ).toFixed( 1 ) }s`;
		}
		return `${ val.toFixed( 0 ) }ms`;
	}
	if ( val >= 1000 ) {
		return `${ ( val / 1000 ).toFixed( 1 ) }K/s`;
	}
	return `${ val.toFixed( val >= 10 ? 0 : 1 ) }/s`;
};

export default function CategoryTimeChart( { data, mode, title } ) {
	const containerRef = useRef( null );
	const svgRef = useRef( null );
	const tooltipRef = useRef( null );
	const lastMouseXRef = useRef( null );

	const categories = useMemo( () => {
		if ( ! data ) {
			return [];
		}
		const totals = {};
		Object.values( data ).forEach( ( bucket ) => {
			Object.entries( bucket ).forEach( ( [ cat, stats ] ) => {
				if ( 'total' === cat ) {
					return;
				}
				const val = mode === 'count' ? stats.c || 0 : stats.t || 0;
				totals[ cat ] = ( totals[ cat ] || 0 ) + val;
			} );
		} );
		return Object.keys( totals ).sort(
			( a, b ) => totals[ b ] - totals[ a ]
		);
	}, [ data, mode ] );

	useEffect( () => {
		if ( ! data || ! containerRef.current || categories.length === 0 ) {
			return;
		}

		const container = containerRef.current;
		const width = container.clientWidth;
		const height = 200;
		const innerW = width - MARGIN.left - MARGIN.right;
		const innerH = height - MARGIN.top - MARGIN.bottom;

		d3.select( svgRef.current ).selectAll( '*' ).remove();

		const svg = d3
			.select( svgRef.current )
			.attr( 'width', width )
			.attr( 'height', height );

		const g = svg
			.append( 'g' )
			.attr( 'transform', `translate(${ MARGIN.left },${ MARGIN.top })` );

		const slots = buildTimeSlots();

		const catSeries = categories.map( ( cat ) => ( {
			cat,
			values: slots.map( ( slot ) => {
				const bucket = data[ slot.bucketKey ];
				const stats = bucket?.[ cat ];
				if ( ! stats ) {
					return { date: slot.date, value: 0 };
				}
				let value;
				if ( mode === 'average' ) {
					value = stats.c > 0 ? stats.t / stats.c : 0;
				} else if ( mode === 'time' ) {
					value = stats.t / 1000 / BUCKET_SECONDS;
				} else {
					value = stats.c / BUCKET_SECONDS;
				}
				return { date: slot.date, value };
			} ),
		} ) );

		const series = catSeries;

		const x = d3
			.scaleTime()
			.domain( d3.extent( slots, ( s ) => s.date ) )
			.range( [ 0, innerW ] );

		const maxVal =
			d3.max( series, ( s ) => d3.max( s.values, ( v ) => v.value ) ) ||
			1;

		const y = d3
			.scaleLinear()
			.domain( [ 0, maxVal * 1.1 ] )
			.range( [ innerH, 0 ] );

		g.append( 'g' )
			.attr( 'transform', `translate(0,${ innerH })` )
			.call(
				d3
					.axisBottom( x )
					.ticks( 8 )
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

		g.append( 'g' )
			.call(
				d3
					.axisLeft( y )
					.ticks( 5 )
					.tickFormat( ( v ) => formatYValue( v, mode ) )
			)
			.selectAll( 'text' )
			.style( 'font-size', '10px' );

		const area = d3
			.area()
			.x( ( d ) => x( d.date ) )
			.y0( innerH )
			.y1( ( d ) => y( d.value ) )
			.curve( d3.curveMonotoneX );

		series.forEach( ( s, i ) => {
			const color = PALETTE[ i % PALETTE.length ];
			g.append( 'path' )
				.datum( s.values )
				.attr( 'fill', color )
				.attr( 'fill-opacity', 0.5 )
				.attr( 'stroke', color )
				.attr( 'stroke-width', 1 )
				.attr( 'd', area );
		} );

		// Highlight bar for selected bucket.
		const bucketWidth = innerW / slots.length;
		const highlight = g
			.append( 'rect' )
			.attr( 'y', 0 )
			.attr( 'height', innerH )
			.attr( 'width', bucketWidth )
			.attr( 'fill', 'rgba(255,255,255,0.1)' )
			.attr( 'stroke', 'rgba(255,255,255,0.3)' )
			.attr( 'stroke-width', 1 )
			.attr( 'opacity', 0 );

		// Tooltip: HTML div outside SVG so it survives re-renders.
		const tooltip = tooltipRef.current;
		const dates = slots.map( ( s ) => s.date );
		const bisect = d3.bisector( ( d ) => d ).left;

		const showTooltip = ( mx ) => {
			const dateAtMouse = x.invert( mx );
			const i1 = Math.min(
				bisect( dates, dateAtMouse ),
				dates.length - 1
			);
			const i0 = Math.max( 0, i1 - 1 );
			const idx =
				dateAtMouse - dates[ i0 ] < dates[ i1 ] - dateAtMouse ? i0 : i1;
			const xPos = x( dates[ idx ] );

			// Show highlight bar on selected bucket.
			highlight.attr( 'x', xPos - bucketWidth / 2 ).attr( 'opacity', 1 );

			// Top 10 categories by value at this bucket.
			const entries = series
				.map( ( s ) => ( {
					cat: s.cat,
					val: s.values[ idx ]?.value || 0,
				} ) )
				.filter( ( e ) => e.val > 0 )
				.sort( ( a, b ) => b.val - a.val )
				.slice( 0, 10 );

			// Build tooltip with safe DOM methods.
			tooltip.textContent = '';
			const header = document.createElement( 'strong' );
			header.textContent = dates[ idx ].toLocaleTimeString();
			tooltip.appendChild( header );
			entries.forEach( ( e ) => {
				tooltip.appendChild( document.createElement( 'br' ) );
				tooltip.appendChild(
					document.createTextNode(
						`${ e.cat }: ${ formatYValue( e.val, mode ) }`
					)
				);
			} );
			tooltip.style.display = 'block';

			// Position below the SVG (including axis labels).
			tooltip.style.left = `${ MARGIN.left + xPos }px`;
			// Position below chart, flip above if it would overflow viewport.
			const belowTop = container.clientHeight;
			tooltip.style.top = `${ belowTop }px`;
			const tooltipRect = tooltip.getBoundingClientRect();
			if ( tooltipRect.bottom > window.innerHeight ) {
				tooltip.style.top = `-${ tooltip.offsetHeight + 4 }px`;
			}
			if ( tooltipRect.right > window.innerWidth ) {
				tooltip.style.left = `${
					MARGIN.left + xPos - tooltip.offsetWidth
				}px`;
			}
		};

		let rafId = null;
		g.append( 'rect' )
			.attr( 'width', innerW )
			.attr( 'height', innerH )
			.attr( 'fill', 'none' )
			.attr( 'pointer-events', 'all' )
			.on( 'mousemove', ( event ) => {
				const [ mx ] = d3.pointer( event );
				lastMouseXRef.current = mx;
				if ( rafId ) {
					cancelAnimationFrame( rafId );
				}
				rafId = requestAnimationFrame( () => {
					rafId = null;
					if ( lastMouseXRef.current === null ) {
						return;
					}
					showTooltip( lastMouseXRef.current );
				} );
			} )
			.on( 'mouseleave', hideTooltip );

		function hideTooltip() {
			if ( rafId ) {
				cancelAnimationFrame( rafId );
				rafId = null;
			}
			lastMouseXRef.current = null;
			tooltip.style.display = 'none';
			highlight.attr( 'opacity', 0 );
		}

		// Restore tooltip if mouse was over chart before re-render.
		if ( lastMouseXRef.current !== null ) {
			showTooltip( lastMouseXRef.current );
		}

		const legend = svg
			.append( 'g' )
			.attr(
				'transform',
				`translate(${ width - MARGIN.right + 10 },${ MARGIN.top })`
			);

		series.forEach( ( s, i ) => {
			const color = PALETTE[ i % PALETTE.length ];
			const ly = i * 16;
			legend
				.append( 'rect' )
				.attr( 'x', 0 )
				.attr( 'y', ly )
				.attr( 'width', 10 )
				.attr( 'height', 10 )
				.attr( 'fill', color );
			legend
				.append( 'text' )
				.attr( 'x', 14 )
				.attr( 'y', ly + 9 )
				.text( s.cat )
				.style( 'font-size', '11px' )
				.style( 'fill', '#888' );
		} );

		// Hide on scroll — chart moves away from cursor without mouseleave.
		const scrollParent =
			container.closest( '.components-modal__content' ) || window;
		scrollParent.addEventListener( 'scroll', hideTooltip, {
			passive: true,
		} );
		return () => {
			scrollParent.removeEventListener( 'scroll', hideTooltip );
		};
	}, [ data, mode, categories ] );

	if ( ! data || Object.keys( data ).length === 0 ) {
		return null;
	}

	return (
		<div
			ref={ containerRef }
			style={ {
				marginBottom: '20px',
				position: 'relative',
				minHeight: '228px',
			} }
		>
			<h3>{ title }</h3>
			<svg ref={ svgRef } />
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
