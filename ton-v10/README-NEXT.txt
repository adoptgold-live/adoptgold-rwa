NEXT STAGE READY

Files added:
- scripts/bootstrap-v10.sh
- scripts/deployV10Collection.ts
- scripts/getV10CollectionData.ts
- scripts/testV10PublicMint.ts
- wrappers/V10NftCollection.ts (getter-aware wrapper)

IMPORTANT:
- contract FunC files are still placeholders
- deploy/build will not succeed until:
  1. stdlib.fc exists
  2. v10_nft_collection.fc is implemented
  3. v10_nft_item.fc is implemented
  4. getter names in FunC match wrapper names

Recommended next order:
1. implement v10_nft_item.fc
2. implement v10_nft_collection.fc
3. run bootstrap-v10.sh
4. run blueprint build V10NftItem
5. run blueprint build V10NftCollection
