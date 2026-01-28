#!/usr/bin/env node

import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import SellingPartner from 'amazon-sp-api';

/* ---------------------------------------------------------
 * ENV bootstrap (ТОЧНО КАК В ORDERS)
 * --------------------------------------------------------- */

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

// SP-API env — override
dotenv.config({
  path: path.resolve(__dirname, '../.env'),
  override: true,
});

// Laravel env — no override
dotenv.config({
  path: path.resolve(__dirname, '../../.env'),
  override: false,
});

/* ---------------------------------------------------------
 * helpers (копируем стиль)
 * --------------------------------------------------------- */

function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function hasFlag(name) {
  return process.argv.includes(`--${name}`);
}

function jsonOut(obj) {
  console.log(JSON.stringify(obj));
}

/* ---------------------------------------------------------
 * main
 * --------------------------------------------------------- */

async function main() {
  const debug = hasFlag('debug');

  const requestId        = Number(getArg('request_id'));
  const marketplaceId    = (getArg('marketplace_id') || '').trim(); // Amazon MarketplaceId
  const amazonOrderId    = (getArg('amazon_order_id') || '').trim();

  const lwaRefreshToken  = (getArg('lwa_refresh_token') || '').trim();
  const lwaClientId      = (getArg('lwa_client_id') || '').trim();
  const lwaClientSecret  = (getArg('lwa_client_secret') || '').trim();

  const awsAccessKeyId     = (getArg('aws_access_key_id') || '').trim();
  const awsSecretAccessKey = (getArg('aws_secret_access_key') || '').trim();
  const awsRoleArn         = (getArg('aws_role_arn') || '').trim();

  const spApiRegion = (getArg('sp_api_region') || 'eu').trim();

  /* ---------------------------------------------------------
   * validation
   * --------------------------------------------------------- */

  if (!requestId || Number.isNaN(requestId)) {
    jsonOut({
      success: false,
      request_id: requestId ?? null,
      status: 'error',
      error: '--request_id is required and must be numeric',
    });
    return;
  }

  if (!marketplaceId || !amazonOrderId) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'error',
      error: '--marketplace_id and --amazon_order_id are required',
    });
    return;
  }

  if (!/^[A-Z0-9]{10,20}$/.test(marketplaceId)) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'error',
      error:
        '--marketplace_id must be Amazon MarketplaceId (e.g. A1PA6795UKMFR9)',
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

  /* ---------------------------------------------------------
   * SP-API call
   * --------------------------------------------------------- */

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

    if (debug) {
      jsonOut({
        debug: true,
        request_id: requestId,
        step: 'sp_api_initialized',
        marketplace_id: marketplaceId,
        amazon_order_id: amazonOrderId,
      });
    }

    const res = await sp.callAPI({
      endpoint: 'solicitations',
      operation: 'createProductReviewAndSellerFeedbackSolicitation',
      path: {
        amazonOrderId: amazonOrderId,
      },
      query: {
        marketplaceIds: [marketplaceId],
      },
      options: {
        version: 'v1',
      },
    });


    jsonOut({
      success: true,
      request_id: requestId,
      status: 'completed',
      data: {
        amazon_order_id: amazonOrderId,
        marketplace_id: marketplaceId,
        sp_api_response: debug ? res : null,
      },
      retry_after_minutes: null,
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
