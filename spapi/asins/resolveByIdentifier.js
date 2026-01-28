#!/usr/bin/env node

import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import SellingPartner from 'amazon-sp-api';

// ---------- load .env ----------
const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

// SP-API env — override=true
dotenv.config({
  path: path.resolve(__dirname, '../.env'),
  override: true,
});

// Laravel env — НЕ override
dotenv.config({
  path: path.resolve(__dirname, '../../.env'),
  override: false,
});

console.log('=== RESOLVE ASIN BY IDENTIFIER (CATALOG API) ===');

// ---------- helpers ----------
function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function jsonOut(obj) {
  console.log(JSON.stringify(obj));
}

// ---------- main ----------
async function main() {
  const requestId = Number(getArg('request_id'));

  const marketplaceId = (getArg('marketplace_id') || '').trim();

  const productIdType = (getArg('product_id_type') || '').trim(); // EAN / GTIN
  const productId     = (getArg('product_id') || '').trim();

  const lwaRefreshToken   = (getArg('lwa_refresh_token') || '').trim();
  const lwaClientId       = (getArg('lwa_client_id') || '').trim();
  const lwaClientSecret   = (getArg('lwa_client_secret') || '').trim();

  const awsAccessKeyId     = (getArg('aws_access_key_id') || '').trim();
  const awsSecretAccessKey = (getArg('aws_secret_access_key') || '').trim();
  const awsRoleArn         = (getArg('aws_role_arn') || '').trim();

  const spApiRegion        = (getArg('sp_api_region') || 'eu').trim();

  // ---------- validation ----------
  if (!requestId || Number.isNaN(requestId)) {
    jsonOut({
      success: false,
      request_id: requestId ?? null,
      status: 'error',
      error: '--request_id is required and must be numeric',
    });
    return;
  }

  if (!marketplaceId || !productIdType || !productId) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'error',
      error: '--marketplace_id, --product_id_type, --product_id are required',
    });
    return;
  }

  const missingAuth = [];
  if (!lwaRefreshToken)    missingAuth.push('lwa_refresh_token');
  if (!lwaClientId)        missingAuth.push('lwa_client_id');
  if (!lwaClientSecret)    missingAuth.push('lwa_client_secret');
  if (!awsAccessKeyId)     missingAuth.push('aws_access_key_id');
  if (!awsSecretAccessKey) missingAuth.push('aws_secret_access_key');
  if (!awsRoleArn)         missingAuth.push('aws_role_arn');

  if (missingAuth.length) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'error',
      error: 'Missing auth args: ' + missingAuth.join(', '),
    });
    return;
  }

  // ---------- SP-API ----------
  try {
    const sp = new SellingPartner({
      region: spApiRegion,
      refresh_token: lwaRefreshToken,
      credentials: {
        SELLING_PARTNER_APP_CLIENT_ID: lwaClientId,
        SELLING_PARTNER_APP_CLIENT_SECRET: lwaClientSecret,
        AWS_ACCESS_KEY_ID: awsAccessKeyId,
        AWS_SECRET_ACCESS_KEY: awsSecretAccessKey,
        AWS_SELLING_PARTNER_ROLE: awsRoleArn,
      },
    });

    console.log('Calling catalogItems.searchCatalogItems (2022-04-01)…');

    const res = await sp.callAPI({
      endpoint: 'catalogItems',
      operation: 'searchCatalogItems',
      query: {
        marketplaceIds: [marketplaceId],
        identifiersType: productIdType,
        identifiers: [productId],
        includedData: ['summaries'],
      },
      options: {
        version: '2022-04-01',
      },
    });

    const items = res?.items || [];

    if (items.length === 1) {
      jsonOut({
        success: true,
        request_id: requestId,
        status: 'resolved',
        resolved_asin: items[0].asin,
        matches: 1,
        raw: res,
      });
      return;
    }

    if (items.length === 0) {
      jsonOut({
        success: true,
        request_id: requestId,
        status: 'not_found',
        matches: 0,
        raw: res,
      });
      return;
    }

    // > 1
    jsonOut({
      success: true,
      request_id: requestId,
      status: 'ambiguous',
      matches: items.length,
      asins: items.map(i => i.asin),
      raw: res,
    });

  } catch (err) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'fail',
      error: err?.message || String(err),
      retry_after_minutes: 5,
    });
  }
}

main();
