import { createRoot } from '@wordpress/element';
import App from './App';
import './styles/admin.scss';

const container = document.getElementById( 'mission-admin' );

if ( container ) {
	const root = createRoot( container );
	root.render( <App container={ container } /> );
}
