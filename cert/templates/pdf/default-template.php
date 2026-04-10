require_once $_SERVER['DOCUMENT_ROOT'] . '/rwa/cert/templates/pdf/default-template.php';

$html = poado_rwa_cert_render_pdf_template([
    'uid' => $uid,
    'type' => $type,
    'label' => $label,
    'short_label' => $shortLabel,
    'group' => $group,
    'weight' => $weight,
    'status_text' => $statusText,
    'issued_at' => $issuedAt,
    'paid_at' => $paidAt,
    'minted_at' => $mintedAt,
    'owner_name' => $ownerNickname,
    'owner_wallet' => $ownerWallet,
    'ton_wallet' => $tonWallet,
    'nft_item_address' => $nftAddress,
    'tx_hash' => $txHash,
    'verify_url' => $verifyUrl,
    'treasury' => $treasury,
    'statement' => $statement,
    'qr_svg' => $qrSvg,
]);