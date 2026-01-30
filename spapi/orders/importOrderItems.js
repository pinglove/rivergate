#!/usr/bin/env node

import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import mysql from 'mysql2/promise';
import SellingPartner from 'amazon-sp-api';

/* =========================================================
 * ENV
 * ========================================================= */

const __filename = fileURLToPath(import.meta.url);
const __dirname  = path.dirname(__filename);

// SP-API env — override (может быть полезно для REGION и т.п., но НЕ для auth)
dotenv.config({
  path: path.resolve(__dirname, '../.env'),
  override: true,
});

// Laravel env — НЕ override (DB creds)
dotenv.config({
  path: path.resolve(__dirname, '../../.env'),
  override: false,
});

/* =========================================================
 * HELPERS
 * ========================================================= */

function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function jsonOut(obj) {
  console.log(JSON.stringify(obj));
}

function money(obj) {
  if (!obj) return { amount: null, currency: null };
  return {
    amount: obj.Amount ?? null,
    currency: obj.CurrencyCode ?? null,
  };
}

function boolToInt(v) {
  if (v === true) return 1;
  if (v === false) return 0;
  return null;
}

function jsonOrNull(v) {
  if (v === undefined || v === null) return null;
  try { return JSON.stringify(v); } catch { return null; }
}

/* =========================================================
 * MAIN: NEW
 * ========================================================= */

async function main() {
  // Amazon MarketplaceId (A1PA6795UKMFR9) — для SP-API
  const marketplaceAmazonId = (getArg('marketplace_id') || '').trim();

  const sellerId    = Number(getArg('seller_id'));
  const workerId    = (getArg('worker_id') || '').trim(); // только для логов
  const orderIdsRaw = (getArg('order_ids') || '').trim();

  // ✅ AUTH — ТОЛЬКО из CLI args (КАК В РАБОЧИХ СКРИПТАХ)
  const lwaRefreshToken   = (getArg('lwa_refresh_token') || '').trim();
  const lwaClientId       = (getArg('lwa_client_id') || '').trim();
  const lwaClientSecret   = (getArg('lwa_client_secret') || '').trim();

  const awsAccessKeyId     = (getArg('aws_access_key_id') || '').trim();
  const awsSecretAccessKey = (getArg('aws_secret_access_key') || '').trim();
  const awsRoleArn         = (getArg('aws_role_arn') || '').trim();

  const spApiRegion        = (getArg('sp_api_region') || process.env.SP_API_REGION || 'eu').trim();

  // ---------- strict validation ----------
  if (!marketplaceAmazonId || !sellerId || !workerId || !orderIdsRaw) {
    jsonOut({
      success: false,
      status: 'error',
      error_code: 'invalid_args',
      error_message: 'Required args missing (marketplace_id, seller_id, worker_id, order_ids)',
      retry_after_minutes: null,
    });
    return;
  }

  const orderIds = orderIdsRaw
    .split(',')
    .map(v => v.trim())
    .filter(Boolean);

  if (!orderIds.length) {
    jsonOut({
      success: false,
      status: 'error',
      error_code: 'invalid_args',
      error_message: 'order_ids is empty',
      retry_after_minutes: null,
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
      status: 'error',
      error_code: 'auth_args_missing',
      error_message: 'Missing auth args: ' + missingAuth.join(', '),
      retry_after_minutes: null,
    });
    return;
  }

  let db;

  try {
    // ---------- DB ----------
    db = await mysql.createConnection({
      host: process.env.DB_HOST,
      user: process.env.DB_USERNAME,
      password: process.env.DB_PASSWORD,
      database: process.env.DB_DATABASE,
      port: process.env.DB_PORT || 3306,
    });

    /* ---------------------------------------------------------
     * Resolve INTERNAL marketplace_id by Amazon marketplace id
     * --------------------------------------------------------- */
    const [[mp]] = await db.execute(
      `
      SELECT id
      FROM marketplaces
      WHERE amazon_id = ?
      LIMIT 1
      `,
      [marketplaceAmazonId]
    );

    if (!mp) {
      jsonOut({
        success: false,
        status: 'error',
        error_code: 'marketplace_not_found',
        error_message: `Marketplace not found for amazon_id=${marketplaceAmazonId}`,
        retry_after_minutes: null,
      });
      return;
    }

    const marketplaceId = mp.id;

    // ---------- SP-API (AUTH FROM CLI!) ----------
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

    let imported = 0;

    // ---------- FETCH + IMPORT ----------
    for (const amazonOrderId of orderIds) {
      let nextToken = null;

      do {
        const res = await sp.callAPI({
          endpoint: 'orders',
          operation: 'getOrderItems',
          path: { orderId: amazonOrderId },
          query: nextToken ? { NextToken: nextToken } : {},
        });

        const payload = res?.payload ?? res;
        const items = Array.isArray(payload?.OrderItems) ? payload.OrderItems : [];

        nextToken = payload?.NextToken || null;

        for (const item of items) {
          const itemPrice        = money(item.ItemPrice);
          const itemTax          = money(item.ItemTax);
          const shipPrice        = money(item.ShippingPrice);
          const shipTax          = money(item.ShippingTax);
          const shipDiscount     = money(item.ShippingDiscount);
          const shipDiscountTax  = money(item.ShippingDiscountTax);
          const promo            = money(item.PromotionDiscount);
          const promoTax         = money(item.PromotionDiscountTax);
          const giftWrapPrice    = money(item.GiftWrapPrice);
          const giftWrapTax      = money(item.GiftWrapTax);
          const giftWrapDiscount = money(item.GiftWrapPriceDiscount);
          const codFee           = money(item.CODFee);
          const codFeeDiscount   = money(item.CODFeeDiscount);

          await db.execute(
            `
            INSERT INTO orders_items (
              marketplace_id,
              seller_id,
              amazon_order_id,
              order_item_id,

              asin,
              seller_sku,
              title,

              condition_id,
              condition_subtype_id,

              quantity_ordered,
              quantity_shipped,

              item_price_amount,
              item_price_currency,
              item_tax_amount,
              item_tax_currency,

              shipping_price_amount,
              shipping_price_currency,
              shipping_tax_amount,
              shipping_tax_currency,

              shipping_discount_amount,
              shipping_discount_currency,
              shipping_discount_tax_amount,
              shipping_discount_tax_currency,

              promotion_discount_amount,
              promotion_discount_currency,
              promotion_discount_tax_amount,
              promotion_discount_tax_currency,

              promotion_ids_json,

              gift_wrap_price_amount,
              gift_wrap_price_currency,
              gift_wrap_tax_amount,
              gift_wrap_tax_currency,
              gift_wrap_price_discount_amount,
              gift_wrap_price_discount_currency,

              cod_fee_amount,
              cod_fee_currency,
              cod_fee_discount_amount,
              cod_fee_discount_currency,

              is_gift,
              gift_message_text,
              gift_wrap_level,
              is_transparency,
              serial_number_required,

              customization_info_json,
              serial_numbers_json,
              product_info_json,
              points_granted_json,
              tax_collection_json,
              item_buyer_info_json,
              item_charge_list_json,
              item_fee_list_json,
              item_tax_withheld_list_json,

              raw_item_json,
              created_at,
              updated_at
            ) VALUES (
              ?,?,?,?,
              ?,?,?,
              ?,?,
              ?,?,
              ?,?,?,?,
              ?,?,?,?,
              ?,?,?,?,
              ?,?,?,?,
              ?,
              ?,?,?,?,?,?,
              ?,?,?,?,
              ?,?,?,?,?,
              ?,?,?,?,?,?,?,?,?,
              ?,
              NOW(),NOW()
            )
            ON DUPLICATE KEY UPDATE
              quantity_ordered = VALUES(quantity_ordered),
              quantity_shipped = VALUES(quantity_shipped),
              raw_item_json    = VALUES(raw_item_json),
              updated_at       = NOW()
            `,
            [
              marketplaceId,
              sellerId,
              amazonOrderId,
              item.OrderItemId,

              item.ASIN ?? null,
              item.SellerSKU ?? null,
              item.Title ?? null,

              item.ConditionId ?? null,
              item.ConditionSubtypeId ?? null,

              item.QuantityOrdered ?? 0,
              item.QuantityShipped ?? null,

              itemPrice.amount,
              itemPrice.currency,
              itemTax.amount,
              itemTax.currency,

              shipPrice.amount,
              shipPrice.currency,
              shipTax.amount,
              shipTax.currency,

              shipDiscount.amount,
              shipDiscount.currency,
              shipDiscountTax.amount,
              shipDiscountTax.currency,

              promo.amount,
              promo.currency,
              promoTax.amount,
              promoTax.currency,

              jsonOrNull(item.PromotionIds),

              giftWrapPrice.amount,
              giftWrapPrice.currency,
              giftWrapTax.amount,
              giftWrapTax.currency,
              giftWrapDiscount.amount,
              giftWrapDiscount.currency,

              codFee.amount,
              codFee.currency,
              codFeeDiscount.amount,
              codFeeDiscount.currency,

              boolToInt(item.IsGift),
              item.GiftMessageText ?? null,
              item.GiftWrapLevel ?? null,
              boolToInt(item.IsTransparency),
              boolToInt(item.SerialNumberRequired),

              jsonOrNull(item.CustomizationInfo),
              jsonOrNull(item.SerialNumbers),
              jsonOrNull(item.ProductInfo),
              jsonOrNull(item.PointsGranted),
              jsonOrNull(item.TaxCollection),
              jsonOrNull(item.BuyerInfo),
              jsonOrNull(item.ItemChargeList),
              jsonOrNull(item.ItemFeeList),
              jsonOrNull(item.ItemTaxWithheldList),

              jsonOrNull(item),
            ]
          );

          imported++;
        }

      } while (nextToken);
    }

    jsonOut({
      success: true,
      status: 'completed',
      data: { imported_items: imported },
      retry_after_minutes: null,
    });

  } catch (err) {
    // ВАЖНО: всегда JSON, даже на неожиданный крэш
    jsonOut({
      success: false,
      status: 'fail',
      error_code: 'import_order_items_error',
      error_message: err?.message || String(err),
      retry_after_minutes: 5,
    });
  } finally {
    if (db) {
      try { await db.end(); } catch {}
    }
  }
}

main();
