import 'dotenv/config';
import { Address, beginCell, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';
import { V8MintRouter } from '../wrappers/V8MintRouter.ts';

export async function run(provider: NetworkProvider) {
  const routerAddress = Address.parse(process.env.V8_ROUTER_ADDRESS || '');
  const ownerAddress = Address.parse(process.env.V8_OWNER || '');

  const router = provider.open(
    V8MintRouter.createFromAddress(routerAddress)
  );

  const content = beginCell()
    .storeBuffer(Buffer.from('test-001.json', 'utf8'))
    .endCell();

  console.log('========================================');
  console.log('V8 TEST MINT');
  console.log('========================================');
  console.log('Router :', routerAddress.toString());
  console.log('Owner  :', ownerAddress.toString());
  console.log('Value  : 0.50 TON');
  console.log('Suffix : test-001.json');
  console.log('========================================');

  await router.sendPublicMint(provider.sender(), {
    owner: ownerAddress,
    content,
    value: toNano('0.60'),
  });

  console.log('Mint tx sent');
}
