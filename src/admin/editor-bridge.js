/**
 * Reads and writes the job edit screen (block editor or classic editor) so accepted DEV3 AI
 * drafts land in the editor and are saved with the post: nothing is written behind the editor's back.
 */

const wpGlobal = () => ( typeof window !== 'undefined' && window.wp ) || {};

/**
 * Whether the block editor store is available.
 *
 * @return {boolean} Block editor.
 */
export function isBlockEditor() {
	const wp = wpGlobal();
	return !! ( wp.data && wp.data.select && wp.data.select( 'core/editor' ) && document.body.classList.contains( 'block-editor-page' ) );
}

/**
 * Current title.
 *
 * @return {string} Title.
 */
export function getTitle() {
	if ( isBlockEditor() ) {
		return wpGlobal().data.select( 'core/editor' ).getEditedPostAttribute( 'title' ) || '';
	}
	const el = document.getElementById( 'title' );
	return el ? el.value : '';
}

/**
 * Current content HTML.
 *
 * @return {string} HTML.
 */
export function getContent() {
	if ( isBlockEditor() ) {
		return wpGlobal().data.select( 'core/editor' ).getEditedPostContent() || '';
	}
	const mce = window.tinymce && window.tinymce.get( 'content' );
	if ( mce && ! mce.isHidden() ) {
		return mce.getContent();
	}
	const ta = document.getElementById( 'content' );
	return ta ? ta.value : '';
}

/**
 * Replaces the content.
 *
 * @param {string} html HTML.
 */
export function setContent( html ) {
	const wp = wpGlobal();
	if ( isBlockEditor() ) {
		const blocks = wp.data.select( 'core/block-editor' ).getBlocks();
		const freeform = blocks.find( ( b ) => b.name === 'core/freeform' );
		if ( freeform && blocks.length === 1 ) {
			wp.data.dispatch( 'core/block-editor' ).updateBlockAttributes( freeform.clientId, { content: html } );
		} else if ( wp.blocks && wp.blocks.createBlock ) {
			wp.data.dispatch( 'core/block-editor' ).resetBlocks( [ wp.blocks.createBlock( 'core/freeform', { content: html } ) ] );
		} else {
			wp.data.dispatch( 'core/editor' ).editPost( { content: html } );
		}
		return;
	}
	const mce = window.tinymce && window.tinymce.get( 'content' );
	if ( mce && ! mce.isHidden() ) {
		mce.setContent( html );
		mce.fire( 'change' );
	}
	const ta = document.getElementById( 'content' );
	if ( ta ) {
		ta.value = html;
		ta.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
	}
}

/**
 * Sets the title.
 *
 * @param {string} title Title.
 */
export function setTitle( title ) {
	if ( isBlockEditor() ) {
		wpGlobal().data.dispatch( 'core/editor' ).editPost( { title } );
		return;
	}
	const el = document.getElementById( 'title' );
	if ( el ) {
		el.value = title;
		el.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
		const label = document.getElementById( 'title-prompt-text' );
		if ( label ) {
			label.classList.add( 'screen-reader-text' );
		}
	}
}

/**
 * Reads a WPJM meta box field (ids are the meta keys, e.g. _job_salary).
 *
 * @param {string} key Meta key.
 * @return {string} Value.
 */
export function getField( key ) {
	const el = document.getElementById( key );
	return el ? el.value : '';
}

/**
 * Writes a WPJM meta box field. Returns false when the field is not on the screen.
 *
 * @param {string} key   Meta key.
 * @param {string} value Value.
 * @return {boolean} Written.
 */
export function setField( key, value ) {
	const el = document.getElementById( key );
	if ( ! el ) {
		return false;
	}
	el.value = value;
	el.dispatchEvent( new window.Event( 'change', { bubbles: true } ) );
	return true;
}

/**
 * Marks the post dirty so "Update" saves meta boxes too.
 */
export function markDirty() {
	const wp = wpGlobal();
	if ( isBlockEditor() ) {
		wp.data.dispatch( 'core/editor' ).editPost( { meta: { ...( wp.data.select( 'core/editor' ).getEditedPostAttribute( 'meta' ) || {} ) } } );
	}
}
