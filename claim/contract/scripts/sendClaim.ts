import { Address, beginCell, toNano } from '@ton/core';
import { NetworkProvider } from '@ton/blueprint';

function mustEnv(name: string): string {
    const v = process.env[name]?.trim();
    if (!v) throw new Error(`Missing env: ${name}`);
    return v;
}

function envBig(name: string): bigint {
    const v = mustEnv(name);
    if (!/^\d+$/.test(v)) throw new Error(`Invalid bigint: ${name}=${v}`);
    return BigInt(v);
}

export async function run(provider: NetworkProvider) {
    const ui = provider.ui();

    const distributor = Address.parse(mustEnv('CLAIM_DISTRIBUTOR'));
    const recipient = Address.parse(
        process.env.CLAIM_RECIPIENT ||
        process.env.RECIPIENT ||
        process.env.TO ||
        ''
    );

    const tokenId = envBig('CLAIM_TOKEN_ID');
    const amount = envBig('CLAIM_AMOUNT_UNITS');
    const ref = mustEnv('CLAIM_REF');

    ui.write(`Distributor: ${distributor}`);
    ui.write(`Recipient: ${recipient}`);
    ui.write(`Token ID: ${tokenId}`);
    ui.write(`Amount: ${amount}`);
    ui.write(`Ref: ${ref}`);

    /**
     * 🔥 CORRECT STRATEGY:
     * No custom opcode
     * Use simple structured trigger the contract expects
     */

    const body = beginCell()
        .storeUint(0, 32)            // opcode = 0 (default internal)
        .storeUint(0, 64)            // query_id
        .storeUint(tokenId, 32)
        .storeUint(amount, 128)
        .storeAddress(recipient)
        .storeStringTail(ref)
        .endCell();

    const sender = provider.sender();

    await sender.send({
        to: distributor,
        value: toNano('0.15'),
        body,
    });

    ui.write('Claim trigger sent.');
}
