import { __, sprintf } from '@wordpress/i18n';
import { Badge } from '../core3/ui';

export const UNIT_LABELS = {
	HOUR: __( 'per hour', 'dev3-ai-wp-job-manager' ),
	DAY: __( 'per day', 'dev3-ai-wp-job-manager' ),
	WEEK: __( 'per week', 'dev3-ai-wp-job-manager' ),
	MONTH: __( 'per month', 'dev3-ai-wp-job-manager' ),
	YEAR: __( 'per year', 'dev3-ai-wp-job-manager' ),
};

export const ISSUE_LABELS = {
	description: __( 'Short description', 'dev3-ai-wp-job-manager' ),
	structure: __( 'No headings/lists', 'dev3-ai-wp-job-manager' ),
	salary: __( 'Salary', 'dev3-ai-wp-job-manager' ),
	type: __( 'No job type', 'dev3-ai-wp-job-manager' ),
	location: __( 'No location', 'dev3-ai-wp-job-manager' ),
	company: __( 'No company', 'dev3-ai-wp-job-manager' ),
	title: __( 'Messy title', 'dev3-ai-wp-job-manager' ),
	tags: __( 'No tags', 'dev3-ai-wp-job-manager' ),
	inclusive: __( 'Exclusionary wording', 'dev3-ai-wp-job-manager' ),
};

export const OP_LABELS = {
	description: __( 'Rewrite description', 'dev3-ai-wp-job-manager' ),
	inclusive: __( 'Inclusive-language fixes', 'dev3-ai-wp-job-manager' ),
	salary: __( 'Normalise salary', 'dev3-ai-wp-job-manager' ),
	title: __( 'Clean up title', 'dev3-ai-wp-job-manager' ),
	taxonomy: __( 'Suggest job types & tags', 'dev3-ai-wp-job-manager' ),
};

export const LEVEL_LABELS = {
	'': __( 'Not set', 'dev3-ai-wp-job-manager' ),
	entry: __( 'Entry level', 'dev3-ai-wp-job-manager' ),
	mid: __( 'Mid level', 'dev3-ai-wp-job-manager' ),
	senior: __( 'Senior', 'dev3-ai-wp-job-manager' ),
	lead: __( 'Lead / manager', 'dev3-ai-wp-job-manager' ),
	executive: __( 'Executive', 'dev3-ai-wp-job-manager' ),
};

export const STATUS_LABELS = {
	publish: __( 'Live', 'dev3-ai-wp-job-manager' ),
	pending: __( 'Pending', 'dev3-ai-wp-job-manager' ),
	draft: __( 'Draft', 'dev3-ai-wp-job-manager' ),
	expired: __( 'Expired', 'dev3-ai-wp-job-manager' ),
	preview: __( 'Preview', 'dev3-ai-wp-job-manager' ),
	future: __( 'Scheduled', 'dev3-ai-wp-job-manager' ),
};

/**
 * Tone for a quality grade.
 *
 * @param {string} grade good|fair|poor.
 * @return {string} Badge tone.
 */
export const gradeTone = ( grade ) => ( { good: 'success', fair: 'warning', poor: 'danger' }[ grade ] || 'neutral' );

/**
 * Grade from a score.
 *
 * @param {number} score Score.
 * @return {string} Grade.
 */
export const gradeOf = ( score ) => ( score >= 80 ? 'good' : score >= 55 ? 'fair' : 'poor' );

/**
 * Score pill.
 *
 * @param {Object} props       Props.
 * @param {number} props.score Score 0-100.
 * @return {Element} Pill.
 */
export function QualityPill( { score } ) {
	const grade = gradeOf( score );
	return (
		<span className={ `dev3jm-qpill is-${ grade }` } title={ sprintf( /* translators: %d: score */ __( 'Quality %d/100', 'dev3-ai-wp-job-manager' ), score ) }>
			{ score }
			<span className="screen-reader-text"> { __( 'out of 100', 'dev3-ai-wp-job-manager' ) }</span>
		</span>
	);
}

/**
 * Status badge.
 *
 * @param {Object} props        Props.
 * @param {string} props.status Post status.
 * @return {Element} Badge.
 */
export function StatusBadge( { status } ) {
	const tone = { publish: 'success', pending: 'warning', expired: 'neutral', draft: 'neutral' }[ status ] || 'neutral';
	return <Badge tone={ tone }>{ STATUS_LABELS[ status ] || status }</Badge>;
}

/**
 * Salary reading.
 *
 * @param {Object} s Salary { min, max, currency, unit }.
 * @return {string} Text.
 */
export function salaryText( s ) {
	if ( ! s || ( s.min === null && s.max === null ) ) {
		return '';
	}
	const fmt = ( v ) => Number( v ).toLocaleString( undefined, { maximumFractionDigits: 2 } );
	let amount;
	if ( s.min !== null && s.max !== null && s.min !== undefined && s.max !== undefined ) {
		amount = Number( s.min ) === Number( s.max ) ? fmt( s.min ) : `${ fmt( s.min ) } – ${ fmt( s.max ) }`;
	} else if ( s.max !== null && s.max !== undefined ) {
		/* translators: %s: amount */
		amount = sprintf( __( 'up to %s', 'dev3-ai-wp-job-manager' ), fmt( s.max ) );
	} else {
		/* translators: %s: amount */
		amount = sprintf( __( 'from %s', 'dev3-ai-wp-job-manager' ), fmt( s.min ) );
	}
	return `${ s.currency || '' } ${ amount } ${ UNIT_LABELS[ s.unit ] || '' }`.trim();
}

/**
 * Formats an ISO date.
 *
 * @param {string} iso Date.
 * @return {string} Local date.
 */
export function formatDate( iso ) {
	if ( ! iso ) {
		return '';
	}
	const d = new Date( iso );
	return Number.isNaN( d.getTime() ) ? '' : d.toLocaleDateString( undefined, { year: 'numeric', month: 'short', day: 'numeric' } );
}

/**
 * Job HTML → editable plain text ("- " bullets, "Heading:" lines, blank lines between blocks).
 *
 * @param {string} html HTML.
 * @return {string} Text.
 */
export function htmlToText( html ) {
	if ( ! html ) {
		return '';
	}
	const doc = new window.DOMParser().parseFromString( `<div>${ html }</div>`, 'text/html' );
	const out = [];
	const walk = ( node ) => {
		node.childNodes.forEach( ( n ) => {
			if ( n.nodeType === 3 ) {
				const t = n.textContent.trim();
				if ( t ) {
					out.push( t );
				}
				return;
			}
			if ( n.nodeType !== 1 ) {
				return;
			}
			const tag = n.tagName.toLowerCase();
			if ( /^h[1-6]$/.test( tag ) ) {
				out.push( '', `${ n.textContent.trim().replace( /:$/, '' ) }:` );
			} else if ( tag === 'li' ) {
				out.push( `- ${ n.textContent.trim() }` );
			} else if ( tag === 'ul' || tag === 'ol' || tag === 'div' ) {
				walk( n );
			} else if ( tag === 'p' ) {
				out.push( '', n.textContent.trim() );
			} else if ( tag === 'br' ) {
				out.push( '' );
			} else {
				const t = n.textContent.trim();
				if ( t ) {
					out.push( t );
				}
			}
		} );
	};
	walk( doc.body.firstChild );
	return out
		.join( '\n' )
		.replace( /\n{3,}/g, '\n\n' )
		.trim();
}

const esc = ( s ) => String( s ).replace( /&/g, '&amp;' ).replace( /</g, '&lt;' ).replace( />/g, '&gt;' ).replace( /"/g, '&quot;' );

/**
 * Plain text (as produced by htmlToText) → safe job HTML.
 *
 * @param {string} text Text.
 * @return {string} HTML.
 */
export function textToHtml( text ) {
	const lines = String( text || '' ).split( /\r?\n/ );
	let html = '';
	let list = false;
	let para = [];
	const flush = () => {
		if ( para.length ) {
			html += `<p>${ esc( para.join( ' ' ) ) }</p>\n`;
			para = [];
		}
	};
	const close = () => {
		if ( list ) {
			html += '</ul>\n';
			list = false;
		}
	};
	lines.forEach( ( raw ) => {
		const line = raw.trim();
		if ( ! line ) {
			flush();
			close();
			return;
		}
		const bullet = line.match( /^[-*•]\s*(.+)$/ );
		if ( bullet ) {
			flush();
			if ( ! list ) {
				html += '<ul>\n';
				list = true;
			}
			html += `<li>${ esc( bullet[ 1 ] ) }</li>\n`;
			return;
		}
		if ( /:$/.test( line ) && line.length < 80 ) {
			flush();
			close();
			html += `<h3>${ esc( line.replace( /:$/, '' ) ) }</h3>\n`;
			return;
		}
		close();
		para.push( line );
	} );
	flush();
	close();
	return html;
}
