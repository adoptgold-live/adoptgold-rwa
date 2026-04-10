// Node TON Jetton verifier (toncenter v3)
import fetch from 'node-fetch';

const TONCENTER_API = 'https://toncenter.com/api/v3';
const API_KEY = process.env.TONCENTER_API_KEY || '';

export async function verifyJettonTransfer({
  address,        // treasury
  amount,         // bigint string
  comment,        // reload_ref
  sender          // user wallet
}) {
  try {
    const url = `${TONCENTER_API}/transactions?account=${address}&limit=20`;

    const res = await fetch(url, {
      headers: API_KEY ? { 'X-API-Key': API_KEY } : {}
    });

    const json = await res.json();

    if (!json.transactions) {
      return { ok: false, reason: 'NO_TX' };
    }

    for (const tx of json.transactions) {
      const msg = tx.in_msg;

      if (!msg) continue;

      const msgComment = msg.message || '';
      const value = msg.value || '0';
      const from = msg.source || '';

      // ===== 3 CRITERIA MATCH =====
      if (
        msgComment.includes(comment) &&
        value === amount &&
        from.toLowerCase() === sender.toLowerCase()
      ) {
        return {
          ok: true,
          tx_hash: tx.hash,
          confirmations: tx.confirmations || 0
        };
      }
    }

    return { ok: false, reason: 'NOT_MATCHED' };

  } catch (e) {
    return { ok: false, reason: 'ERROR', error: e.message };
  }
}