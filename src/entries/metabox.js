import { createElement, render } from '@wordpress/element';
import * as element from '@wordpress/element';
import Metabox from '../admin/Metabox';
import '../core3/admin-kit.css';
import '../admin/admin.css';

const node = document.getElementById( 'dev3jm-metabox-root' );
if ( node ) {
	const props = { postId: Number( node.dataset.post ), appUrl: node.dataset.app };
	if ( element.createRoot ) {
		element.createRoot( node ).render( createElement( Metabox, props ) );
	} else {
		render( createElement( Metabox, props ), node );
	}
}
