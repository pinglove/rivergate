#!/usr/bin/env node
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import SellingPartner from 'amazon-sp-api';

// ---------- load .env ----------
const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

// ⚠️ SP-API env — override=true (КАК В РАБОЧЕМ ПРИМЕРЕ)
dotenv.config({
  path: path.resolve(__dirname, '../.env'),
  override: true,
});

// Laravel env (НЕ override)
dotenv.config({
  path: path.resolve(__dirname, '../../.env'),
  override: false,
});

console.log('=== LOAD CATALOG VERSION MARKER v2026-01-22 ===');

// ---------- helpers ----------
function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function safeDump(obj, maxLen = 20000) {
  let s;
  try {
    s = JSON.stringify(obj, null, 2);
  } catch {
    s = String(obj);
  }
  if (s && s.length > maxLen) {
    s = s.slice(0, maxLen) + `\n...TRUNCATED (${s.length} chars)`;
  }
  return s;
}

// ---------- main ----------
async function main() {
  const marketplaceId = (getArg('marketplace_id') || '').trim();

  // ✅ auth теперь приходит ТОЛЬКО из CLI (из БД через Laravel)
  const lwaRefreshToken   = (getArg('lwa_refresh_token') || '').trim();
  const lwaClientId       = (getArg('lwa_client_id') || '').trim();
  const lwaClientSecret   = (getArg('lwa_client_secret') || '').trim();

  const awsAccessKeyId     = (getArg('aws_access_key_id') || '').trim();
  const awsSecretAccessKey = (getArg('aws_secret_access_key') || '').trim();
  const awsRoleArn         = (getArg('aws_role_arn') || '').trim();

  const spApiRegion        = (getArg('sp_api_region') || 'eu').trim();

  if (!marketplaceId) {
    console.log(JSON.stringify({
      success: false,
      error_code: 'invalid_args',
      error_message: '--marketplace_id is required',
    }));
    return;
  }

  const missing = [];
  if (!lwaRefreshToken)   missing.push('lwa_refresh_token');
  if (!lwaClientId)       missing.push('lwa_client_id');
  if (!lwaClientSecret)   missing.push('lwa_client_secret');
  if (!awsAccessKeyId)     missing.push('aws_access_key_id');
  if (!awsSecretAccessKey) missing.push('aws_secret_access_key');
  if (!awsRoleArn)         missing.push('aws_role_arn');

  if (missing.length) {
    console.log(JSON.stringify({
      success: false,
      error_code: 'auth_args_missing',
      error_message: 'Missing required args: ' + missing.map(x => `--${x}=`).join(', '),
    }));
    return;
  }

  try {
    const sp = new SellingPartner({
      region: spApiRegion || 'eu',
      refresh_token: lwaRefreshToken,
      credentials: {
        SELLING_PARTNER_APP_CLIENT_ID: lwaClientId,
        SELLING_PARTNER_APP_CLIENT_SECRET: lwaClientSecret,
        AWS_ACCESS_KEY_ID: awsAccessKeyId,
        AWS_SECRET_ACCESS_KEY: awsSecretAccessKey,
        AWS_SELLING_PARTNER_ROLE: awsRoleArn,
      },
    });

    console.log('Calling catalogItems.searchCatalogItems …');

    const res = await sp.callAPI({
      endpoint: 'catalogItems',
      operation: 'searchCatalogItems',
      query: {
        marketplaceIds: [marketplaceId],
        keywords: ['towel'],      // любое слово, гарантированно существующее
        includedData: ['summaries'],
        pageSize: 10,
      },
    });

    console.log('=== AMAZON RAW RESPONSE ===');
    console.log(safeDump(res));

    console.log(JSON.stringify({
      success: true,
      items_count: Array.isArray(res?.items) ? res.items.length : 0,
    }));

  } catch (err) {
    console.log('=== AMAZON RES DEBUG (error) ===');
    console.log(
      safeDump({
        name: err?.name,
        message: err?.message,
        stack: err?.stack,
      })
    );

    console.log(JSON.stringify({
      success: false,
      error_code: 'catalog_load_error',
      error_message: err?.message || String(err),
    }));
  }
}

main();
