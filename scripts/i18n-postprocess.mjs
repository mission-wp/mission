#!/usr/bin/env node
/**
 * Post-process `wp i18n make-json` output.
 *
 * Two fixes:
 *
 * 1. Correct the admin bundle JSON filename. WP-CLI's make-json truncates the
 *    `source` path of our large minified admin bundle (it writes
 *    `admin/build/mission-a.js` instead of `admin/build/mission-admin.js`), so
 *    the file is md5-named for the wrong path and WordPress never loads it.
 *    We rename it to the md5 WordPress actually looks up and fix the `source`.
 *
 * 2. Prune JSON files whose source is not a compiled bundle (no "/build/" in
 *    the path) — e.g. "src" components and "assets/shared" modules. Those files
 *    are never enqueued; their strings are compiled into the admin/block
 *    bundles, which carry their own JSON. The extras are dead weight in the zip.
 *
 * Idempotent: safe to run repeatedly. Handles every locale present.
 */

import { createHash } from 'node:crypto';
import { readdirSync, readFileSync, writeFileSync, renameSync, rmSync } from 'node:fs';
import { join } from 'node:path';

const LANG_DIR = 'languages';
const DOMAIN = 'mission-donation-platform';

// Built bundles that are actually enqueued and whose make-json `source` may be
// corrupted. Key = directory the bundle lives in (unique per bundle); value =
// the real plugin-relative path WordPress uses to derive the JSON md5.
const BUNDLE_FIXES = [
  { dir: 'admin/build', path: 'admin/build/mission-admin.js' },
];

const md5 = ( s ) => createHash( 'md5' ).update( s ).digest( 'hex' );
const jsonName = ( locale, scriptPath ) =>
  `${ DOMAIN }-${ locale }-${ md5( scriptPath ) }.json`;

const FILE_RE = new RegExp( `^${ DOMAIN }-(.+)-([0-9a-f]{32})\\.json$` );

let renamed = 0;
let pruned = 0;

for ( const file of readdirSync( LANG_DIR ) ) {
  const match = file.match( FILE_RE );
  if ( ! match ) {
    continue;
  }

  const locale = match[ 1 ];
  const full = join( LANG_DIR, file );
  const data = JSON.parse( readFileSync( full, 'utf8' ) );
  const source = ( data.source || '' ).replace( /\\\//g, '/' );

  // Fix 1: correct the bundle filename + source.
  const fix = BUNDLE_FIXES.find( ( f ) => source.startsWith( f.dir + '/' ) );
  if ( fix ) {
    const target = jsonName( locale, fix.path );
    data.source = fix.path;
    writeFileSync( full, JSON.stringify( data, null, '\t' ) + '\n' );
    if ( file !== target ) {
      renameSync( full, join( LANG_DIR, target ) );
      renamed++;
    }
    continue;
  }

  // Fix 2: prune JSON for non-enqueued scripts (anything not a build bundle).
  if ( ! source.includes( '/build/' ) ) {
    rmSync( full );
    pruned++;
  }
}

console.log( `i18n-postprocess: renamed ${ renamed } bundle JSON, pruned ${ pruned } unused src JSON` );
