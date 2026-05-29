/**
 * Shim for the `react-jsx-runtime` script handle that @wordpress/scripts
 * expects as a dependency. WP 6.6+ registers this handle natively, but
 * older installs (and some wp-env images) do not. Build it from React.
 *
 * @package PedimentAi
 */
( function ( React ) {
	if ( ! React ) { return; }
	if ( window.ReactJSXRuntime ) { return; }

	function jsx( type, props, key ) {
		var rest = {};
		var children;
		if ( props ) {
			for ( var k in props ) {
				if ( ! Object.prototype.hasOwnProperty.call( props, k ) ) { continue; }
				if ( k === 'children' ) { children = props.children; }
				else { rest[ k ] = props[ k ]; }
			}
		}
		if ( key !== undefined ) { rest.key = key; }
		if ( children === undefined ) {
			return React.createElement( type, rest );
		}
		if ( Array.isArray( children ) ) {
			return React.createElement.apply( null, [ type, rest ].concat( children ) );
		}
		return React.createElement( type, rest, children );
	}

	window.ReactJSXRuntime = {
		jsx: jsx,
		jsxs: jsx,
		jsxDEV: jsx,
		Fragment: React.Fragment,
	};
} )( window.React );
