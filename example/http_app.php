<?php

namespace app\shorten;

use std\{
    str,
    bytes,
};

use app\{
    http,
    store,
    config,
    Route,
    Method,
    Status,
};

const LINK_CODE_ENTROPY_BYTES = 5;

#![local]
enum LinkError
{
    case BadJson;
    case InvalidUrl;
    case SaveFailed;
}

#[Route(Method::POST, '/links')]
function link_create(http\Ctx $ctx): void
{
    $target = link_parse($ctx->request->body);
    if ($target instanceof LinkError) {
        link_write_error($ctx, $target);
        return;
    }

    $code  = link_generate_code(LINK_CODE_ENTROPY_BYTES);
    $saved = store\link_insert_unique($ctx->db, $code, $target);
    if ($saved === false) {
        link_write_error($ctx, LinkError::SaveFailed);
        return;
    }

    http\ctx_json(
        $ctx,
        http\Status::CREATED,
        ['short' => config\BASE_URL . '/' . $code]
    );
}

#[Route(Method::GET, '/{code}')]
function link_resolve(http\Ctx $ctx): void
{
    $code = http\ctx_param($ctx, 'code');

    $target = store\link_find($ctx->db, $code);
    if ($target === null) {
        http\ctx_text($ctx, http\Status::NOT_FOUND, 'not found');
        return;
    }

    http\ctx_redirect($ctx, http\Status::FOUND, $target);
}

#![local]
function link_parse(string $body): string|LinkError
{
    $data = json_decode($body, associative: true);
    if (!is_array($data)) {
        return LinkError::BadJson;
    }
    if (!isset($data['url'])) {
        return LinkError::BadJson;
    }
    if (!is_string($data['url'])) {
        return LinkError::BadJson;
    }

    $url = str\trim($data['url']);
    if (!link_url_valid($url)) {
        return LinkError::InvalidUrl;
    }
    return $url;
}

#![local]
function link_url_valid(string $url): bool
{
    $is_http  = str\starts_with($url, 'http://');
    $is_https = str\starts_with($url, 'https://');
    if (!$is_http && !$is_https) {
        return false;
    }
    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}

#![local]
function link_generate_code(int $bytes): string
{
    assert($bytes > 0);

    return bytes\to_hex(random_bytes($bytes));
}

#![local]
function link_write_error(http\Ctx $ctx, LinkError $error): void
{
    $status = match ($error) {
        LinkError::BadJson    => Status::BAD_REQUEST,
        LinkError::InvalidUrl => Status::UNPROCESSABLE_ENTITY,
        LinkError::SaveFailed => Status::INTERNAL_SERVER_ERROR,
    };

    $message = match ($error) {
        LinkError::BadJson    => 'bad json',
        LinkError::InvalidUrl => 'invalid url',
        LinkError::SaveFailed => 'save failed',
    };

    http\ctx_json($ctx, $status, ['error' => $message]);
}