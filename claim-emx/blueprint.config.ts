import { Config } from '@ton/blueprint';

const config: Config = {
  network: {
    endpoint: process.env.TON_API_ENDPOINT || 'https://toncenter.com/api/v2/jsonRPC',
    type: 'mainnet'
  }
};

export default config;
