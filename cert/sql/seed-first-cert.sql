USE wems_db;

SET @cert_uid       := 'RK92-EMA-20260327-REAL0001';
SET @owner_user_id  := 13;
SET @owner_address  := 'UQBxc1nE_MGtIQpy1wTzVnoQTPfQmv5st_u2QJWSNNAvbYAv';
SET @cert_type      := 'gold';
SET @family_key     := 'GENESIS';
SET @rwa_code       := 'RK92-EMA';
SET @status_value   := 'payment_confirmed';
SET @mint_status    := 'mint_pending';
SET @item_index     := 1;
SET @metadata_url   := 'https://adoptgold.app/rwa/metadata/cert/U13/RK92-EMA-20260327-REAL0001/metadata.json';

INSERT INTO poado_rwa_certs (
    cert_uid,
    owner_user_id,
    owner_address,
    cert_type,
    family_key,
    rwa_code,
    status,
    mint_status,
    item_index,
    metadata_url,
    created_at,
    updated_at
) VALUES (
    @cert_uid,
    @owner_user_id,
    @owner_address,
    @cert_type,
    @family_key,
    @rwa_code,
    @status_value,
    @mint_status,
    @item_index,
    @metadata_url,
    UTC_TIMESTAMP(),
    UTC_TIMESTAMP()
);
