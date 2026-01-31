#!/usr/bin/env node

import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import SellingPartner from 'amazon-sp-api';

// ---------- load .env ----------
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

// ---------- helpers ----------
function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function jsonOut(obj) {
  console.log(JSON.stringify(obj));
}

function toIsoZ(v) {
  if (!v) return null;
  const d = new Date(v);
  if (Number.isNaN(d.getTime())) return null;
  return d.toISOString().replace('.000', '');
}

function toMysqlDatetime(v) {
  if (!v) return null;
  if (
    v === '1970-01-01T00:00:00Z' ||
    v.startsWith('0001-01-01')
  ) {
    return null;
  }
  return v.replace('T', ' ').replace('Z', '');
}

function jsonOrNull(v) {
  if (v === undefined || v === null) return null;
  try { return JSON.stringify(v); } catch { return null; }
}

// ---------- main ----------
async function main() {
  const requestId = Number(getArg('request_id'));

  // EXPECTED: Amazon MarketplaceId (e.g. A1PA6795UKMFR9), NOT "DE"
  const marketplaceId = (getArg('marketplace_id') || '').trim();

  const fromDate = getArg('from');
  const toDate   = getArg('to');

  const fromIso = toIsoZ(fromDate);
  const toIso   = toIsoZ(toDate);

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

  if (!marketplaceId || !fromIso) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'error',
      error: '--marketplace_id and --from are required',
    });
    return;
  }

  // 🔒 CRITICAL FIX: ensure Amazon MarketplaceId, not country code
  if (!/^[A-Z0-9]{10,20}$/.test(marketplaceId)) {
    jsonOut({
      success: false,
      request_id: requestId,
      status: 'error',
      error:
        '--marketplace_id must be Amazon MarketplaceId (e.g. A1PA6795UKMFR9), ' +
        'NOT country code like DE / FR',
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

    let nextToken = null;
    let orders = [];

    do {
      const res = await sp.callAPI({
        endpoint: 'orders',
        operation: 'getOrders',
        query: nextToken
          ? { NextToken: nextToken }
          : {
              MarketplaceIds: [marketplaceId],
              //LastUpdatedAfter: fromIso,
              CreatedAfter: fromIso,
              //...(toIso ? { LastUpdatedBefore: toIso } : {}),
              ...(toIso ? { CreatedBefore: toIso } : {}),
              OrderStatuses: [
                'Pending',
                'Unshipped',
                'PartiallyShipped',
                'Shipped',
                'Canceled',
              ],
              MaxResultsPerPage: 100,
            },
      });

      const batch = Array.isArray(res?.Orders) ? res.Orders : [];

      for (const o of batch) {
        orders.push({
          amazon_order_id: o.AmazonOrderId,
          merchant_order_id: o.SellerOrderId ?? null,

          purchase_date: toMysqlDatetime(o.PurchaseDate),
          last_updated_date: toMysqlDatetime(o.LastUpdateDate),

          order_status: o.OrderStatus ?? null,
          order_type: o.OrderType ?? null,

          fulfillment_channel: o.FulfillmentChannel ?? null,
          sales_channel: o.SalesChannel ?? null,
          order_channel: o.OrderChannel ?? null,

          ship_service_level: o.ShipmentServiceLevel ?? null,
          shipment_service_level_category: o.ShipmentServiceLevelCategory ?? null,

          ship_city: o.ShippingAddress?.City ?? null,
          ship_state: o.ShippingAddress?.StateOrRegion ?? null,
          ship_postal_code: o.ShippingAddress?.PostalCode ?? null,
          ship_country: o.ShippingAddress?.CountryCode ?? null,

          is_business_order: o.IsBusinessOrder ? 1 : 0,
          is_prime: o.IsPrime ? 1 : 0,
          is_premium_order: o.IsPremiumOrder ? 1 : 0,
          is_replacement_order: o.IsReplacementOrder ? 1 : 0,
          is_sold_by_ab: o.IsSoldByAB ? 1 : 0,
          is_ispu: o.IsISPU ? 1 : 0,
          is_global_express_enabled: o.IsGlobalExpressEnabled ? 1 : 0,
          is_access_point_order: o.IsAccessPointOrder ? 1 : 0,
          has_regulated_items: o.HasRegulatedItems ? 1 : 0,
          is_iba: o.IsIBA ? 1 : 0,

          purchase_order_number: o.PurchaseOrderNumber ?? null,
          price_designation: o.PriceDesignation ?? null,

          order_total_amount: o.OrderTotal?.Amount ?? null,
          order_total_currency: o.OrderTotal?.CurrencyCode ?? null,

          number_of_items_shipped: o.NumberOfItemsShipped ?? null,
          number_of_items_unshipped: o.NumberOfItemsUnshipped ?? null,

          earliest_ship_date: toMysqlDatetime(o.EarliestShipDate),
          latest_ship_date: toMysqlDatetime(o.LatestShipDate),
          earliest_delivery_date: toMysqlDatetime(o.EarliestDeliveryDate),
          latest_delivery_date: toMysqlDatetime(o.LatestDeliveryDate),

          payment_method: o.PaymentMethod ?? null,
          payment_method_details_json: jsonOrNull(o.PaymentMethodDetails),
          buyer_invoice_preference: o.BuyerInvoicePreference ?? null,
          buyer_info_json: jsonOrNull(o.BuyerInfo),

          raw_order_json: jsonOrNull(o),
        });
      }

      nextToken = res?.NextToken || null;

    } while (nextToken);

    jsonOut({
      success: true,
      request_id: requestId,
      status: 'completed',
      data: {
        orders_fetched: orders.length,
        orders,
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
