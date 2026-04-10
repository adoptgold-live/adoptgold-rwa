# V9 One-Contract Public Mint

## Goal
Replace router-dependent public mint with direct public mint collection.

## Flow
User -> V9 Collection -> NFT Item

## Public Mint
- sender pays TON directly to collection
- collection mints NFT to sender
- collection sends primary treasury share
- collection refunds excess if any

## Admin
- set owner
- set content
- set treasury
- pause/unpause
- owner mint
- withdraw
- sweep

## Royalty
- factor = 2500
- base = 10000
- address = treasury
- equals 25% royalty getter

## Important
- V8 remains frozen
- V9 is a new clean workspace
- finalize mint integration must be updated to use V9 collection address and OP_PUBLIC_MINT
