/**
 * Writing assistant for WP Job Manager's front-end submit form. Plain DOM (no React) to keep the
 * visitor footprint small. Visitor-facing text is neutral: no product branding.
 */
import { __, sprintf } from '@wordpress/i18n';

const cfg = () => window.dev3jmSubmit || {};
const $ = ( sel, root = document ) => root.querySelector( sel );

/**
 * Element helper.
 *
 * @param {string} tag   Tag.
 * @param {Object} attrs Attributes (class, text, html is never used).
 * @param {Array}  kids  Children.
 * @return {HTMLElement} Element.
 */
export function h( tag, attrs = {}, kids = [] ) {
	const el = document.createElement( tag );
	Object.keys( attrs ).forEach( ( k ) => {
		if ( k === 'text' ) {
			el.textContent = attrs[ k ];
		} else if ( k === 'class' ) {
			el.className = attrs[ k ];
		} else if ( k.startsWith( 'on' ) ) {
			el.addEventListener( k.slice( 2 ).toLowerCase(), attrs[ k ] );
		} else if ( attrs[ k ] !== false && attrs[ k ] !== undefined && attrs[ k ] !== null ) {
			el.setAttribute( k, attrs[ k ] === true ? '' : attrs[ k ] );
		}
	} );
	kids.filter( Boolean ).forEach( ( k ) => el.append( k ) );
	return el;
}

/**
 * POST to a public endpoint.
 *
 * @param {string} path Path.
 * @param {Object} body Body.
 * @return {Promise<Object>} JSON.
 */
export function post( path, body ) {
	const base = cfg().restUrl || '/wp-json/dev3jm/v1/public/';
	const url = base.indexOf( '?' ) === -1 ? base + path : base.replace( /\/?$/, '' ) + '/' + path;
	return window
		.fetch( url, {
			method: 'POST',
			credentials: 'same-origin',
			headers: { 'Content-Type': 'application/json' },
			body: JSON.stringify( body ),
		} )
		.then( ( r ) =>
			r.json().then( ( data ) => {
				if ( ! r.ok ) {
					throw data;
				}
				return data;
			} )
		);
}

/**
 * Current form values.
 *
 * @return {Object} Values.
 */
export function readForm() {
	const val = ( id ) => {
		const el = document.getElementById( id );
		return el ? el.value : '';
	};
	const remote = document.getElementById( 'remote_position' );
	const typeSel = document.getElementById( 'job_type' );
	return {
		title: val( 'job_title' ),
		content: getDescription(),
		company: val( 'company_name' ),
		tagline: val( 'company_tagline' ),
		location: val( 'job_location' ),
		remote: !! ( remote && remote.checked ),
		salary: val( 'job_salary' ),
		salary_currency: val( 'job_salary_currency' ),
		salary_unit: val( 'job_salary_unit' ),
		types: typeSel
			? Array.from( typeSel.selectedOptions || [] )
					.filter( ( o ) => o.value )
					.map( ( o ) => o.textContent.trim() )
					.join( ', ' )
			: '',
	};
}

/**
 * Description from TinyMCE or the textarea.
 *
 * @return {string} HTML.
 */
export function getDescription() {
	const mce = window.tinymce && window.tinymce.get( 'job_description' );
	if ( mce && ! mce.isHidden() ) {
		return mce.getContent();
	}
	const ta = document.getElementById( 'job_description' );
	return ta ? ta.value : '';
}

/**
 * Writes the description.
 *
 * @param {string} html HTML.
 */
export function setDescription( html ) {
	const mce = window.tinymce && window.tinymce.get( 'job_description' );
	if ( mce ) {
		mce.setContent( html );
		mce.save();
	}
	const ta = document.getElementById( 'job_description' );
	if ( ta ) {
		ta.value = html;
		ta.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
	}
}

/**
 * Case-preserving replacement (mirrors the server's Inclusive::apply).
 *
 * @param {string} html       HTML.
 * @param {string} phrase     Phrase.
 * @param {string} suggestion Replacement.
 * @return {string} HTML.
 */
export function replacePhrase( html, phrase, suggestion ) {
	const re = new RegExp( phrase.replace( /[.*+?^${}()|[\]\\]/g, '\\$&' ), 'gi' );
	return html
		.replace( re, ( m ) => {
			if ( ! suggestion ) {
				return '';
			}
			return m.charAt( 0 ) !== m.charAt( 0 ).toLowerCase() ? suggestion.charAt( 0 ).toUpperCase() + suggestion.slice( 1 ) : suggestion;
		} )
		.replace( / {2,}/g, ' ' );
}

/**
 * Safe preview of server HTML (only p/h3/ul/li/strong/em survive, as text nodes).
 *
 * @param {string} html HTML.
 * @return {DocumentFragment} Nodes.
 */
export function safePreview( html ) {
	const doc = new window.DOMParser().parseFromString( `<div>${ html }</div>`, 'text/html' );
	const frag = document.createDocumentFragment();
	const allowed = [ 'P', 'H3', 'H4', 'UL', 'OL', 'LI', 'STRONG', 'EM', 'BR' ];
	const copy = ( src, dest ) => {
		src.childNodes.forEach( ( n ) => {
			if ( n.nodeType === 3 ) {
				dest.append( document.createTextNode( n.textContent ) );
			} else if ( n.nodeType === 1 ) {
				if ( [ 'SCRIPT', 'STYLE', 'TEMPLATE', 'IFRAME' ].includes( n.tagName ) ) {
					return;
				}
				if ( allowed.includes( n.tagName ) ) {
					const el = document.createElement( n.tagName.toLowerCase() );
					copy( n, el );
					dest.append( el );
				} else {
					copy( n, dest );
				}
			}
		} );
	};
	copy( doc.body.firstChild, frag );
	return frag;
}

const errorText = ( e ) => ( e && e.message ) || __( 'Something went wrong. Please try again.', 'dev3-ai-wp-job-manager' );

/**
 * Builds the assistant panel.
 *
 * @param {HTMLElement} mount Mount.
 */
export function initPanel( mount ) {
	const c = cfg();
	if ( ! c.writer && ! c.inclusive && ! c.suggest ) {
		return;
	}
	const desc = $( '.fieldset-job_description' );
	if ( desc && desc.parentNode ) {
		desc.parentNode.insertBefore( mount, desc.nextSibling );
	}
	mount.hidden = false;
	const live = h( 'p', { class: 'dev3jm-assist__live', role: 'status', 'aria-live': 'polite' } );
	const body = h( 'div', { class: 'dev3jm-assist__body' } );
	const setBusy = ( btn, on ) => {
		btn.disabled = on;
		btn.setAttribute( 'aria-busy', on ? 'true' : 'false' );
		btn.classList.toggle( 'is-busy', on );
	};
	const say = ( msg ) => {
		live.textContent = msg;
	};

	const showWriter = () => {
		body.replaceChildren();
		const notesId = 'dev3jm-notes';
		const toneId = 'dev3jm-tone';
		const notes = h( 'textarea', { id: notesId, rows: 4, class: 'dev3jm-assist__input', placeholder: __( 'e.g. Small team, React and TypeScript, 4-day week, mentoring budget', 'dev3-ai-wp-job-manager' ) } );
		const tone = h(
			'select',
			{ id: toneId, class: 'dev3jm-assist__input' },
			[
				[ 'professional', __( 'Professional', 'dev3-ai-wp-job-manager' ) ],
				[ 'friendly', __( 'Friendly', 'dev3-ai-wp-job-manager' ) ],
				[ 'startup', __( 'Startup', 'dev3-ai-wp-job-manager' ) ],
				[ 'formal', __( 'Formal', 'dev3-ai-wp-job-manager' ) ],
			].map( ( [ v, l ] ) => h( 'option', { value: v, text: l, selected: v === c.tone } ) )
		);
		const out = h( 'div', { class: 'dev3jm-assist__out' } );
		const go = h( 'button', { type: 'button', class: 'dev3jm-assist__btn is-primary', text: __( 'Write description', 'dev3-ai-wp-job-manager' ) } );
		go.addEventListener( 'click', () => {
			setBusy( go, true );
			say( __( 'Writing…', 'dev3-ai-wp-job-manager' ) );
			post( 'write', { ...readForm(), notes: notes.value, tone: tone.value } )
				.then( ( d ) => {
					out.replaceChildren();
					const preview = h( 'div', { class: 'dev3jm-assist__preview', tabindex: '0', 'aria-label': __( 'Suggested description', 'dev3-ai-wp-job-manager' ) } );
					preview.append( safePreview( d.content ) );
					const use = h( 'button', { type: 'button', class: 'dev3jm-assist__btn is-primary', text: __( 'Use this text', 'dev3-ai-wp-job-manager' ) } );
					use.addEventListener( 'click', () => {
						setDescription( d.content );
						const title = document.getElementById( 'job_title' );
						if ( title && ! title.value && d.title ) {
							title.value = d.title;
						}
						out.replaceChildren( h( 'p', { class: 'dev3jm-assist__ok', text: __( 'Added to the description. You can still edit it.', 'dev3-ai-wp-job-manager' ) } ) );
						say( __( 'Description updated.', 'dev3-ai-wp-job-manager' ) );
					} );
					const discard = h( 'button', { type: 'button', class: 'dev3jm-assist__btn', text: __( 'Discard', 'dev3-ai-wp-job-manager' ) } );
					discard.addEventListener( 'click', () => out.replaceChildren() );
					out.append( preview, h( 'div', { class: 'dev3jm-assist__actions' }, [ use, discard ] ) );
					say( __( 'Suggestion ready. Review it below.', 'dev3-ai-wp-job-manager' ) );
				} )
				.catch( ( e ) => {
					out.replaceChildren( h( 'p', { class: 'dev3jm-assist__err', role: 'alert', text: errorText( e ) } ) );
					say( '' );
				} )
				.finally( () => setBusy( go, false ) );
		} );
		body.append(
			h( 'label', { for: notesId, class: 'dev3jm-assist__label', text: __( 'Describe the role in a few words or bullet points', 'dev3-ai-wp-job-manager' ) } ),
			notes,
			h( 'label', { for: toneId, class: 'dev3jm-assist__label', text: __( 'Tone', 'dev3-ai-wp-job-manager' ) } ),
			tone,
			h( 'div', { class: 'dev3jm-assist__actions' }, [ go ] ),
			out
		);
		notes.focus();
	};

	const showCheck = ( btn ) => {
		setBusy( btn, true );
		say( __( 'Checking the wording…', 'dev3-ai-wp-job-manager' ) );
		post( 'inclusive', { content: getDescription() } )
			.then( ( d ) => {
				body.replaceChildren();
				if ( ! d.issues.length ) {
					body.append( h( 'p', { class: 'dev3jm-assist__ok', text: __( 'Nice: no exclusionary wording found.', 'dev3-ai-wp-job-manager' ) } ) );
					say( __( 'No issues found.', 'dev3-ai-wp-job-manager' ) );
					return;
				}
				const list = h( 'ul', { class: 'dev3jm-assist__issues' } );
				d.issues.forEach( ( i ) => {
					const fix = h( 'button', { type: 'button', class: 'dev3jm-assist__btn', text: i.suggestion ? __( 'Replace', 'dev3-ai-wp-job-manager' ) : __( 'Remove', 'dev3-ai-wp-job-manager' ) } );
					const li = h( 'li', {}, [ h( 'span', {}, [ h( 'strong', { text: `“${ i.phrase }”` } ), i.suggestion ? document.createTextNode( ` → “${ i.suggestion }”` ) : null, h( 'small', { text: i.reason } ) ] ), fix ] );
					fix.addEventListener( 'click', () => {
						setDescription( replacePhrase( getDescription(), i.phrase, i.suggestion ) );
						li.remove();
						say( __( 'Phrase replaced.', 'dev3-ai-wp-job-manager' ) );
					} );
					list.append( li );
				} );
				body.append( h( 'p', { class: 'dev3jm-assist__label', text: sprintf( /* translators: %d: count */ __( '%d phrases may put some candidates off:', 'dev3-ai-wp-job-manager' ), d.issues.length ) } ), list );
				say( sprintf( /* translators: %d: count */ __( '%d phrases found.', 'dev3-ai-wp-job-manager' ), d.issues.length ) );
			} )
			.catch( ( e ) => {
				body.replaceChildren( h( 'p', { class: 'dev3jm-assist__err', role: 'alert', text: errorText( e ) } ) );
			} )
			.finally( () => setBusy( btn, false ) );
	};

	const showSuggest = ( btn ) => {
		setBusy( btn, true );
		say( __( 'Finding job type and tags…', 'dev3-ai-wp-job-manager' ) );
		post( 'suggest', readForm() )
			.then( ( d ) => {
				body.replaceChildren();
				const typeSel = document.getElementById( 'job_type' );
				const tagsField = document.getElementById( 'job_tags' );
				const rows = [];
				if ( typeSel && d.types.length ) {
					const apply = h( 'button', { type: 'button', class: 'dev3jm-assist__btn', text: __( 'Select', 'dev3-ai-wp-job-manager' ) } );
					apply.addEventListener( 'click', () => {
						const ids = d.types.map( ( t ) => String( t.id ) );
						Array.from( typeSel.options ).forEach( ( o ) => {
							if ( typeSel.multiple ) {
								o.selected = o.selected || ids.includes( o.value );
							} else if ( o.value === ids[ 0 ] ) {
								o.selected = true;
							}
						} );
						typeSel.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
						if ( window.jQuery ) {
							window.jQuery( typeSel ).trigger( 'change' );
						}
						say( __( 'Job type selected.', 'dev3-ai-wp-job-manager' ) );
					} );
					rows.push( h( 'li', {}, [ h( 'span', {}, [ h( 'small', { text: __( 'Job type', 'dev3-ai-wp-job-manager' ) } ), h( 'strong', { text: d.types.map( ( t ) => t.name ).join( ', ' ) } ) ] ), apply ] ) );
				}
				if ( d.tags.length ) {
					const apply = tagsField ? h( 'button', { type: 'button', class: 'dev3jm-assist__btn', text: __( 'Add tags', 'dev3-ai-wp-job-manager' ) } ) : null;
					if ( apply ) {
						apply.addEventListener( 'click', () => {
							const have = tagsField.value
								.split( ',' )
								.map( ( t ) => t.trim() )
								.filter( Boolean );
							const lower = have.map( ( t ) => t.toLowerCase() );
							tagsField.value = have.concat( d.tags.filter( ( t ) => ! lower.includes( t.toLowerCase() ) ) ).join( ', ' );
							say( __( 'Tags added.', 'dev3-ai-wp-job-manager' ) );
						} );
					}
					rows.push( h( 'li', {}, [ h( 'span', {}, [ h( 'small', { text: __( 'Skills / tags', 'dev3-ai-wp-job-manager' ) } ), h( 'strong', { text: d.tags.join( ', ' ) } ) ] ), apply ] ) );
				}
				if ( ! rows.length ) {
					body.append( h( 'p', { text: __( 'Add a title and a description first, then try again.', 'dev3-ai-wp-job-manager' ) } ) );
				} else {
					body.append( h( 'ul', { class: 'dev3jm-assist__issues' }, rows ) );
				}
				say( __( 'Suggestions ready.', 'dev3-ai-wp-job-manager' ) );
			} )
			.catch( ( e ) => body.replaceChildren( h( 'p', { class: 'dev3jm-assist__err', role: 'alert', text: errorText( e ) } ) ) )
			.finally( () => setBusy( btn, false ) );
	};

	const bar = h( 'div', { class: 'dev3jm-assist__bar' } );
	if ( c.writer ) {
		bar.append( h( 'button', { type: 'button', class: 'dev3jm-assist__btn is-primary', text: __( 'Help me write it', 'dev3-ai-wp-job-manager' ), onClick: showWriter } ) );
	}
	if ( c.inclusive ) {
		const b = h( 'button', { type: 'button', class: 'dev3jm-assist__btn', text: __( 'Check wording', 'dev3-ai-wp-job-manager' ) } );
		b.addEventListener( 'click', () => showCheck( b ) );
		bar.append( b );
	}
	if ( c.suggest ) {
		const b = h( 'button', { type: 'button', class: 'dev3jm-assist__btn', text: __( 'Suggest type & tags', 'dev3-ai-wp-job-manager' ) } );
		b.addEventListener( 'click', () => showSuggest( b ) );
		bar.append( b );
	}
	mount.append( h( 'p', { class: 'dev3jm-assist__title', text: __( 'Writing assistant', 'dev3-ai-wp-job-manager' ) } ), h( 'p', { class: 'dev3jm-assist__hint', text: __( 'Get a clear, inclusive job ad in seconds. Nothing changes until you choose to use a suggestion.', 'dev3-ai-wp-job-manager' ) } ), bar, live, body );
}

/**
 * Live salary reading under WPJM's salary field.
 */
export function initSalary() {
	const input = document.getElementById( 'job_salary' );
	if ( ! input || ! cfg().salary ) {
		return;
	}
	const hint = h( 'p', { class: 'dev3jm-salary', role: 'status', 'aria-live': 'polite', id: 'dev3jm-salary-hint' } );
	input.setAttribute( 'aria-describedby', `${ input.getAttribute( 'aria-describedby' ) || '' } dev3jm-salary-hint`.trim() );
	input.parentNode.append( hint );
	let timer = null;
	const run = () => {
		const f = readForm();
		if ( ! f.salary.trim() ) {
			hint.replaceChildren();
			return;
		}
		post( 'salary', f )
			.then( ( d ) => {
				hint.replaceChildren();
				if ( ! d.salary ) {
					hint.append( document.createTextNode( d.negotiable ? __( 'Tip: a salary range gets more applicants than “competitive”.', 'dev3-ai-wp-job-manager' ) : __( 'Tip: use numbers, e.g. 50,000 - 70,000.', 'dev3-ai-wp-job-manager' ) ) );
					hint.className = 'dev3jm-salary is-tip';
					return;
				}
				hint.className = 'dev3jm-salary is-ok';
				hint.append( document.createTextNode( `${ __( 'Reads as', 'dev3-ai-wp-job-manager' ) } ` ), h( 'strong', { text: d.reading } ) );
				const cur = document.getElementById( 'job_salary_currency' );
				const unit = document.getElementById( 'job_salary_unit' );
				if ( ( cur && cur.value !== d.salary.currency ) || ( unit && unit.value !== d.salary.unit ) ) {
					const set = h( 'button', { type: 'button', class: 'dev3jm-assist__link', text: __( 'Set currency and period', 'dev3-ai-wp-job-manager' ) } );
					set.addEventListener( 'click', () => {
						if ( cur ) {
							cur.value = d.salary.currency;
						}
						if ( unit ) {
							unit.value = d.salary.unit;
						}
						set.remove();
					} );
					hint.append( document.createTextNode( ' ' ), set );
				}
			} )
			.catch( () => hint.replaceChildren() );
	};
	input.addEventListener( 'input', () => {
		window.clearTimeout( timer );
		timer = window.setTimeout( run, 600 );
	} );
	if ( input.value ) {
		run();
	}
}

/**
 * Boot.
 */
export function boot() {
	const mount = document.getElementById( 'dev3jm-assistant' );
	if ( mount ) {
		initPanel( mount );
	}
	initSalary();
}
