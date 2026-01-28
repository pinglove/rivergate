#!/usr/bin/env node
import dotenv from 'dotenv';
import path from 'path';
import { fileURLToPath } from 'url';
import fs from 'fs';
import https from 'https';
import zlib from 'zlib';
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

console.log('=== POLL CATALOG REPORT v2026-01-22 ===');

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

/**
 * Windows-safe rename with retries (EBUSY / EPERM)
 */
async function safeRename(src, dst, attempts = 5, delayMs = 300) {
  for (let i = 1; i <= attempts; i++) {
    try {
      fs.renameSync(src, dst);
      return;
    } catch (err) {
      if (!['EBUSY', 'EPERM'].includes(err.code) || i === attempts) {
        throw err;
      }
      await new Promise(r => setTimeout(r, delayMs));
    }
  }
}

function download(url, dest) {
  return new Promise((resolve, reject) => {
    const file = fs.createWriteStream(dest);
    https.get(url, res => {
      if (res.statusCode !== 200) {
        reject(new Error(`HTTP ${res.statusCode}`));
        return;
      }

      res.pipe(file);

      file.on('finish', () => {
        file.close(resolve);
      });
    }).on('error', err => {
      try { file.close(); } catch {}
      reject(err);
    });
  });
}

// ---------- main ----------
async function main() {
  const reportId = (getArg('report_id') || '').trim();
  if (!reportId) {
    console.log(JSON.stringify({
      success: false,
      error_code: 'invalid_args',
      error_message: '--report_id is required',
    }));
    return;
  }

  // ✅ auth приходит из CLI (из БД через Laravel)
  const lwaRefreshToken   = (getArg('lwa_refresh_token') || '').trim();
  const lwaClientId       = (getArg('lwa_client_id') || '').trim();
  const lwaClientSecret   = (getArg('lwa_client_secret') || '').trim();

  const awsAccessKeyId     = (getArg('aws_access_key_id') || '').trim();
  const awsSecretAccessKey = (getArg('aws_secret_access_key') || '').trim();
  const awsRoleArn         = (getArg('aws_role_arn') || '').trim();

  const spApiRegion        = (getArg('sp_api_region') || 'eu').trim();

  const missing = [];
  if (!lwaRefreshToken) missing.push('lwa_refresh_token');
  if (!lwaClientId) missing.push('lwa_client_id');
  if (!lwaClientSecret) missing.push('lwa_client_secret');
  if (!awsAccessKeyId) missing.push('aws_access_key_id');
  if (!awsSecretAccessKey) missing.push('aws_secret_access_key');
  if (!awsRoleArn) missing.push('aws_role_arn');

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

    // 1️⃣ getReport
    const report = await sp.callAPI({
      endpoint: 'reports',
      operation: 'getReport',
      path: { reportId },
    });

    console.log('Report status:', report.processingStatus);

    if (report.processingStatus !== 'DONE') {
      console.log(JSON.stringify({
        success: true,
        status: report.processingStatus,
        retry_after_minutes: 1,
      }));
      return;
    }

    // 2️⃣ getReportDocument
    const doc = await sp.callAPI({
      endpoint: 'reports',
      operation: 'getReportDocument',
      path: { reportDocumentId: report.reportDocumentId },
    });

    const outDir = path.resolve(__dirname, 'downloads');
    fs.mkdirSync(outDir, { recursive: true });

    const rawPath = path.join(outDir, `catalog_${reportId}.raw`);
    const tsvPath = path.join(outDir, `catalog_${reportId}.tsv`);

    // 3️⃣ download raw file
    await download(doc.url, rawPath);

    // 4️⃣ decompress ONLY if needed
    if (doc.compressionAlgorithm === 'GZIP') {
      await new Promise((resolve, reject) => {
        const read   = fs.createReadStream(rawPath);
        const gunzip = zlib.createGunzip();
        const write  = fs.createWriteStream(tsvPath);

        read
          .pipe(gunzip)
          .pipe(write)
          .on('finish', resolve)
          .on('error', reject);
      });

      fs.unlinkSync(rawPath);

    } else {
      await safeRename(rawPath, tsvPath);
    }

    console.log(JSON.stringify({
      success: true,
      file: tsvPath,
      compression: doc.compressionAlgorithm || 'NONE',
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
      error_code: 'poll_report_error',
      error_message: err?.message || String(err),
    }));
  }
}

main();
