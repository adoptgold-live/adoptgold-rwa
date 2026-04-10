const TelegramBot = require('node-telegram-bot-api');

const BOT_TOKEN = process.env.BOT_TOKEN || '';
const TG_BOT_SECRET = process.env.TG_BOT_SECRET || '';
const ISSUE_PIN_URL = process.env.ISSUE_PIN_URL || 'https://adoptgold.app/rwa/auth/tg/issue.php';
const TG_PIN_PAGE_URL = process.env.TG_PIN_PAGE_URL || 'https://adoptgold.app/rwa/tg-pin.php';

if (!BOT_TOKEN) {
  throw new Error('BOT_TOKEN is missing');
}

const bot = new TelegramBot(BOT_TOKEN, { polling: true });

async function issuePin(tgId) {
  const res = await fetch(ISSUE_PIN_URL, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'X-TG-BOT-SECRET': TG_BOT_SECRET
    },
    body: JSON.stringify({ tg_id: String(tgId) })
  });

  let data = {};
  try {
    data = await res.json();
  } catch (e) {
    throw new Error('Invalid JSON from issue.php');
  }

  const plainPin = data.token || data.pin || null;

  if (!res.ok || !data.ok || !plainPin) {
    throw new Error(data.error || data.message || 'PIN issue failed');
  }

  return {
    ...data,
    token: String(plainPin),
    expires_in: Number(data.expires_in || 180)
  };
}

function pinCard(pin, expiresIn = 180) {
  const safePin = String(pin);
  const safeExp = String(expiresIn);
  const url = TG_PIN_PAGE_URL;

  return [
    '<pre>',
    '══════════════════════════════',
    ' POAdo Dashboard',
    ' Telegram PIN Login / 验证码登录',
    '══════════════════════════════',
    '',
    '[EN]',
    ` PIN     : ${safePin}`,
    ` Expires : ${safeExp}s`,
    ` Login   : ${url}`,
    '',
    '[中文]',
    ` 验证码   : ${safePin}`,
    ` 有效期   : ${safeExp}秒`,
    ` 登录页   : ${url}`,
    '',
    '© 2025 Blockchain Group Ltd.',
    'RWA Standard Organisation (RSO)',
    '══════════════════════════════',
    '</pre>'
  ].join('\n');
}

function helpText() {
  return [
    'AdoptGold Telegram PIN Login / 验证码登录',
    '',
    'Commands / 指令',
    '/start - Start / 开始',
    '/login - Generate PIN / 生成验证码',
    '/help - Help / 帮助',
    '',
    `PIN Page / 登录页:\n${TG_PIN_PAGE_URL}`
  ].join('\n');
}

function keyboard() {
  return {
    inline_keyboard: [
      [{ text: 'Open PIN Login / 打开验证码登录', url: TG_PIN_PAGE_URL }],
      [{ text: 'Generate New PIN / 重新生成验证码', callback_data: 'gen_pin' }]
    ]
  };
}

bot.onText(/\/start(?:\s+login)?/i, async (msg) => {
  await bot.sendMessage(msg.chat.id, helpText(), {
    disable_web_page_preview: true,
    reply_markup: keyboard()
  });
});

bot.onText(/\/help/i, async (msg) => {
  await bot.sendMessage(msg.chat.id, helpText(), {
    disable_web_page_preview: true,
    reply_markup: keyboard()
  });
});

bot.onText(/\/login/i, async (msg) => {
  try {
    const data = await issuePin(msg.from.id);
    await bot.sendMessage(
      msg.chat.id,
      pinCard(data.token, data.expires_in || 180),
      {
        parse_mode: 'HTML',
        disable_web_page_preview: true,
        reply_markup: keyboard()
      }
    );
  } catch (err) {
    await bot.sendMessage(
      msg.chat.id,
      `PIN generation failed / 验证码生成失败\n\n${err.message || 'Unknown error'}`,
      {
        disable_web_page_preview: true,
        reply_markup: keyboard()
      }
    );
  }
});

bot.on('callback_query', async (query) => {
  try {
    if (query.data === 'gen_pin') {
      const data = await issuePin(query.from.id);
      await bot.editMessageText(
        pinCard(data.token, data.expires_in || 180),
        {
          chat_id: query.message.chat.id,
          message_id: query.message.message_id,
          parse_mode: 'HTML',
          disable_web_page_preview: true,
          reply_markup: keyboard()
        }
      );
    }
    await bot.answerCallbackQuery(query.id);
  } catch (err) {
    await bot.answerCallbackQuery(query.id, {
      text: 'PIN generation failed / 验证码生成失败',
      show_alert: true
    });
  }
});