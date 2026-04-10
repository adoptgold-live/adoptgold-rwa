import { Config } from '@ton/blueprint';

export const config: Config = {
  network: {
    endpoint: process.env.TON_RPC_URL || 'https://mainnet-v4.tonhubapi.com',
  },
};
