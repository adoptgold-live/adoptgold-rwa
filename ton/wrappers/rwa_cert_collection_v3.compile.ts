/**
 * /var/www/html/public/rwa/ton/wrappers/rwa_cert_collection_v3.compile.ts
 * Version: v3.0.0-20260330-public-mint-033-ton
 *
 * Blueprint compile target for RWA Cert Collection V3
 */

import { CompilerConfig } from '@ton/blueprint';

export const compile: CompilerConfig = {
  lang: 'func',
  targets: ['contracts/rwa_cert_collection_v3.fc'],
};
