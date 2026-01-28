#!/usr/bin/env node
import fs from 'fs';
import readline from 'readline';
import path from 'path';
import dotenv from 'dotenv';
import mysql from 'mysql2/promise';
import iconv from 'iconv-lite';

dotenv.config({ path: path.resolve(process.cwd(), '.env'), override: false });

console.log('=== IMPORT ASIN CATALOG v2026-01-22 ===');

function getArg(name) {
  const arg = process.argv.find(a => a.startsWith(`--${name}=`));
  return arg ? arg.split('=').slice(1).join('=') : null;
}

function pickAsin(row) {
  // 1️⃣ DE / UK / US
  if (row['asin1']?.trim()) return row['asin1'].trim();
  if (row['asin2']?.trim()) return row['asin2'].trim();
  if (row['asin3']?.trim()) return row['asin3'].trim();

  // 2️⃣ FR / IT / ES (product-id-type = 1 → ASIN)
  const type = row['product-id-type']?.trim();
  const pid  = row['product-id']?.trim();

  if (type === '1' && pid) {
    return pid;
  }

  return null;
}

async function main() {
  const file = getArg('file');
  const userId = Number(getArg('user_id'));
  const marketplaceId = Number(getArg('marketplace_id'));

  if (!file || !userId || !marketplaceId) {
    console.log(JSON.stringify({
      success: false,
      error_code: 'invalid_args',
      error_message: '--file, --user_id, --marketplace_id are required',
    }));
    return;
  }

  if (!fs.existsSync(file)) {
    console.log(JSON.stringify({
      success: false,
      error_code: 'file_not_found',
      error_message: file,
    }));
    return;
  }

  const db = await mysql.createConnection({
    host: process.env.DB_HOST,
    user: process.env.DB_USERNAME,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_DATABASE,
    port: process.env.DB_PORT || 3306,
    charset: 'utf8mb4',
  });

  const input = fs
    .createReadStream(file)
    .pipe(iconv.decodeStream('windows-1252'));

  const rl = readline.createInterface({
    input,
    crlfDelay: Infinity,
  });

  let headers = [];
  let imported = 0;
  let unresolved = 0;
  let lineNo = 0;

  const unresolvedDetails = {
    no_asin_columns: 0,
    product_id_type_not_1: 0,
    empty_product_id: 0,
    unknown: 0,
  };

  const unresolvedSamples = [];

  try {
    for await (const line of rl) {
      lineNo++;

      if (!headers.length) {
        headers = line.split('\t').map(h => h.trim().toLowerCase());
        continue;
      }

      if (!line.trim()) continue;

      const values = line.split('\t');
      const row = Object.fromEntries(
        headers.map((h, i) => [h, values[i]])
      );

      const asin = pickAsin(row);

      /**
       * ------------------------------------------------------------------
       * ✅ NORMAL PATH — ASIN есть → asins_asins
       * ------------------------------------------------------------------
       */
      if (asin) {
        await db.execute(
          `
          INSERT INTO asins_asins
            (user_id, marketplace_id, asin, title, status, created_at, updated_at)
          VALUES (?, ?, ?, ?, 'active', NOW(), NOW())
          ON DUPLICATE KEY UPDATE
            title = VALUES(title),
            updated_at = NOW()
          `,
          [
            userId,
            marketplaceId,
            asin,
            row['item-name'] || null,
          ]
        );

        imported++;
        continue;
      }

      /**
       * ------------------------------------------------------------------
       * 🟡 UNRESOLVED PATH — ASIN нет → asins_user_mp_sync_unresolved
       * ------------------------------------------------------------------
       */
      unresolved++;

      const pidType = row['product-id-type']?.trim() || null;
      const pid     = row['product-id']?.trim() || null;

      let reason = 'unknown';

      if (!row['asin1'] && !row['asin2'] && !row['asin3']) {
        if (!pidType) {
          unresolvedDetails.no_asin_columns++;
          reason = 'no_asin_columns';
        } else if (pidType !== '1') {
          unresolvedDetails.product_id_type_not_1++;
          reason = 'product_id_type_not_1';
        } else if (!pid) {
          unresolvedDetails.empty_product_id++;
          reason = 'empty_product_id';
        }
      } else {
        unresolvedDetails.unknown++;
      }

      await db.execute(
        `
        INSERT INTO asins_user_mp_sync_unresolved
          (user_id, marketplace_id, seller_sku, product_id_type, product_id, title, status, raw_row, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, 'pending', ?, NOW(), NOW())
        `,
        [
          userId,
          marketplaceId,
          row['seller-sku'] || null,
          pidType,
          pid,
          row['item-name'] || null,
          JSON.stringify({
            line: lineNo,
            reason,
            row,
          }),
        ]
      );

      if (unresolvedSamples.length < 10) {
        unresolvedSamples.push({
          line: lineNo,
          reason,
          'product-id-type': pidType,
          'product-id': pid,
          asin1: row['asin1'],
          asin2: row['asin2'],
          asin3: row['asin3'],
          'item-name': row['item-name'],
        });
      }
    }

    /**
     * ------------------------------------------------------------------
     * 🔍 DEBUG OUTPUT — видно в воркере
     * ------------------------------------------------------------------
     */
    console.log(JSON.stringify({
      unresolved_details: unresolvedDetails,
      unresolved_samples: unresolvedSamples,
    }, null, 2));

    /**
     * ------------------------------------------------------------------
     * ✅ FINAL RESULT — ВОРКЕР ЭТО ПАРСИТ
     * ------------------------------------------------------------------
     */
    console.log(JSON.stringify({
      success: true,
      imported,
      unresolved,
    }));

  } catch (err) {
    console.log(JSON.stringify({
      success: false,
      error_code: 'import_error',
      error_message: err.message,
    }));
  } finally {
    await db.end();
  }
}

main();
