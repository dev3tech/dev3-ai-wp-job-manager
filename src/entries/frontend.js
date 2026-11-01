import { boot } from '../frontend/submit';
import '../frontend/submit.css';

if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', boot );
} else {
	boot();
}
