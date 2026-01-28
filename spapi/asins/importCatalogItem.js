#!/usr/bin/env node
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import mysql from 'mysql2/promise';

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

console.log('=== IMPORT CATALOG ITEM FROM DB v2026-01-24 ===');

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
  const syncId = Number(getArg('sync_id'));

  if (!syncId) {
    jsonOut({
      success: false,
      error_code: 'invalid_args',
      error_message: '--sync_id is required',
    });
    return;
  }

  const db = await mysql.createConnection({
    host: process.env.DB_HOST,
    port: process.env.DB_PORT || 3306,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_DATABASE,
    charset: 'utf8mb4',
  });

  try {
    // 1️⃣ sync
    const [[sync]] = await db.query(
      `SELECT * FROM asins_asin_listing_sync WHERE id = ?`,
      [syncId]
    );

    if (!sync) {
      throw new Error(`sync_id ${syncId} not found`);
    }

    // 2️⃣ последний payload
    const [[log]] = await db.query(
      `
      SELECT payload
      FROM asins_asin_listing_sync_logs
      WHERE sync_id = ?
      ORDER BY id DESC
      LIMIT 1
      `,
      [syncId]
    );

    if (!log || !log.payload) {
      throw new Error('payload not found in sync logs');
    }

    let payload = JSON.parse(log.payload);

    if (typeof payload === 'string') {
      payload = JSON.parse(payload);
    }

    // 3️⃣ НОРМАЛИЗАЦИЯ PAYLOAD (ФИКС)
    let res = null;

    if (payload?.raw?.data?.response) {
      res = payload.raw.data.response;
    } else if (payload?.data?.response) {
      res = payload.data.response;
    } else if (payload?.response) {
      res = payload.response;
    } else if (payload?.asin) {
      res = payload;
    } else {
      throw new Error('invalid payload structure (amazon response not found)');
    }

    if (!res?.asin) {
      throw new Error('amazon response has no asin');
    }

    const asin = res.asin;

    // 4️⃣ upsert listing
    await db.query(
      `
      INSERT INTO asins_asin_listing
      (user_id, marketplace_id, asin, data, created_at, updated_at)
      VALUES (?, ?, ?, ?, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        data = VALUES(data),
        updated_at = NOW()
      `,
      [
        sync.user_id,
        sync.marketplace_id,
        asin,
        JSON.stringify(res),
      ]
    );

    // 5️⃣ listing_id
    const [[listing]] = await db.query(
      `
      SELECT id
      FROM asins_asin_listing
      WHERE user_id = ? AND marketplace_id = ? AND asin = ?
      `,
      [sync.user_id, sync.marketplace_id, asin]
    );

    if (!listing) {
      throw new Error('listing not found after upsert');
    }

    const listingId = listing.id;

    // 6️⃣ images: replace set
    await db.query(
      `DELETE FROM asins_asin_listing_images WHERE listing_id = ?`,
      [listingId]
    );

    const images = res.images?.[0]?.images || [];

    for (const img of images) {
      await db.query(
        `
        INSERT INTO asins_asin_listing_images
        (listing_id, variant, url, width, height, created_at)
        VALUES (?, ?, ?, ?, ?, NOW())
        `,
        [
          listingId,
          img.variant || null,
          img.link || null,
          img.width || null,
          img.height || null,
        ]
      );
    }

    jsonOut({
      success: true,
      data: {
        sync_id: syncId,
        asin,
        images_imported: images.length,
      },
    });

  } catch (err) {
    jsonOut({
      success: false,
      error_code: 'import_failed',
      error_message: err.message,
    });
  } finally {
    await db.end();
  }
}

main();
