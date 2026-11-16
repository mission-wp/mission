#!/usr/bin/env node
/**
 * Copy a translated catalog to the regional locales that share it.
 *
 * WordPress only loads a plugin catalog whose locale matches the site's exactly,
 * so a site set to French (Canada) would stay in English with only fr_FR shipped.
 * Each variant .po is regenerated from its source on every run; translate the
 * source locale only.
 */

import { readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const LANG_DIR = 'languages';
const DOMAIN = 'mission-donation-platform';

// Source locale => regional locales served by a copy of it.
const VARIANTS = {
  fr_FR: {
    fr_CA: 'French (Canada)',
    fr_BE: 'French (Belgium)',
  },
};

const poPath = ( locale ) => join( LANG_DIR, `${ DOMAIN }-${ locale }.po` );

let copied = 0;

for ( const [ source, variants ] of Object.entries( VARIANTS ) ) {
  const catalog = readFileSync( poPath( source ), 'utf8' );

  for ( const [ locale, team ] of Object.entries( variants ) ) {
    writeFileSync(
      poPath( locale ),
      catalog
        .replace( /^"Language: .*\\n"$/m, `"Language: ${ locale }\\n"` )
        .replace( /^"Language-Team: .*\\n"$/m, `"Language-Team: ${ team }\\n"` )
    );
    copied++;
  }
}

console.log( `i18n-variants: wrote ${ copied } regional catalogs` );
