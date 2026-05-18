/**
 * Client Octopus Projects — React Entry Point
 *
 * Mounts the ProjectsApp component into #co-projects-root.
 */
import { render } from '@wordpress/element';
import ProjectsApp from './components/ProjectsApp';

const root = document.getElementById( 'co-projects-root' );

if ( root ) {
	render( <ProjectsApp />, root );
}
