Procurement Flow for Laravel

概要 (Japanese)

このパッケージは、調達（Procurement）業務のための Livewire ベース UI、ルーティング、設定、翻訳、PDF 生成や受入スキャン（QR）などのワークフローを提供する Laravel パッケージです。アプリケーションに組み込むことで、下記の機能をすぐに利用できます。

- ダッシュボード / 発注一覧・詳細 / 仕入先一覧
- 資材（Materials）一覧・詳細・払出（Issue）画面、SDS ダウンロード
- 受入（Receiving）スキャン、発注作成用の注文スキャン（Ordering）
- 調達関連の設定（オプション・承認フロー・税・PDF・カテゴリ・トークン／ラベル）
- 画面、翻訳（日本語/英語）の公開・上書き

English Overview

This package provides procurement workflows for Laravel: Livewire-based pages, routes, configuration, translations, PDF generation, receiving QR scan, and more. Drop it into your application and get a ready-to-use procurement module.

Features

- Dashboard, Purchase Orders (index/show/pdf), Suppliers index
- Materials: index/show/issue, secure SDS download (signed)
- Receiving: QR scan and post; Ordering: QR scan to create draft POs
- Settings screens: Options, Approval (flow selection), Taxes, PDF, Categories, Tokens, Labels
- View and translation namespaces; publishable config and language files

Requirements

- PHP: ^8.0
- Laravel Framework: ^12.0（Illuminate Support 12）
- Livewire v3（アプリ側のセットアップに従います）
- Dependencies (installed via composer):
  - lastdino/approval-flow ^0.1
  - tuncaybahadir/quar ^1.7
  - lastdino/chrome-laravel ^0.1
  - spatie/laravel-medialibrary ^11.0

Installation

1) Install via Composer

```
composer require lastdino/procurement-flow
```

In a monorepo setup (packages/lastdino/procurement-flow), ensure your root composer is configured to load the package, or require it by path/version accordingly.

2) Service Provider

The service provider is auto-discovered:

```
Lastdino\ProcurementFlow\ProcurementFlowServiceProvider
```

3) Publish Config and Translations (optional)

```
php artisan vendor:publish --tag=procurement-flow-config --no-interaction
php artisan vendor:publish --tag=procurement-flow-lang --no-interaction
```

Configuration

Config key: `procurement_flow` (note the underscore). Publish destination: `config/procurement-flow.php`.

Available options (excerpt):

- `route_prefix`: UI の URL プレフィックス（既定: `procurement`）
- `middleware`: UI に適用するミドルウェア（既定: `['web', 'auth']`）
- `enabled`: 機能の有効/無効フラグ
- `ghs`
  - `disk`: GHS ピクトグラム画像のストレージディスク名（例: `public`）
  - `directory`: ディスク直下の保存ディレクトリ（例: `ghs_labels`）
  - `map`: GHS キーとファイル名のマッピング（例: `GHS01` => `GHS01.bmp` など）
  - `placeholder`: 未定義または欠損時のプレースホルダファイル名（`null`で非表示）

Routes

All web routes are grouped by the configured prefix and middleware. Default prefix is `/procurement`.

Named routes (selection):

- `procurement.dashboard` → `/`
- Purchase Orders:
  - `procurement.purchase-orders.index` → `/purchase-orders`
  - `procurement.purchase-orders.show` → `/purchase-orders/{po}`
  - `procurement.purchase-orders.pdf` → `/purchase-orders/{po}/pdf`
- Pending Receiving:
  - `procurement.pending-receiving.index` → `/pending-receiving`
- Materials:
  - `procurement.materials.index` → `/materials`
  - `procurement.materials.show` → `/materials/{material}`
  - `procurement.materials.issue` → `/materials/{material}/issue`
  - `procurement.materials.sds.download` (signed) → `/materials/{material}/sds`
- Suppliers:
  - `procurement.suppliers.index` → `/suppliers`
- Receiving Scan:
  - `procurement.receiving.scan` → `/receivings/scan`
  - `procurement.receiving.scan.info` → `/receivings/scan/info/{token}`
  - `procurement.receiving.scan.receive` → `/receivings/scan/receive`
- Settings:
  - `procurement.settings.options` → `/settings/options`
  - `procurement.settings.approval` → `/settings/approval`
  - `procurement.settings.taxes` → `/settings/taxes`
  - `procurement.settings.pdf` → `/settings/pdf`
  - `procurement.settings.categories` → `/settings/categories`
  - `procurement.settings.tokens` → `/settings/tokens`
  - `procurement.settings.labels` → `/settings/labels`
- Ordering Scan:
  - `procurement.ordering.scan` → `/ordering/scan`

Views & Translations Namespaces

- Views: namespace `procflow`（例: `procflow::livewire.procurement.materials.index`）
- Translations: namespace `procflow`（例: `__('procflow::materials.table.name')`）

Livewire Components

These components are registered by the service provider and are referenced in routes/views:

- `procurement.dashboard`
- `purchase-orders.index`, `purchase-orders.show`
- `suppliers.index`
- `procurement.materials`, `procurement.materials.issue`
- `procurement.pending-receiving.index`
- `procurement.receiving.scan`
- `procurement.ordering.scan`
- Settings:
  - `procurement.settings.options.index`
  - `procurement.settings.approval.index`
  - `procurement.settings.taxes.index`
  - `procurement.settings.pdf.index`
  - `procurement.settings.categories.index`
  - `procurement.settings.tokens.index`
  - `procurement.settings.tokens.labels`

Materials: SDS and GHS

- SDS（安全データシート）
  - Secure download is served via a signed + authenticated route: `procurement.materials.sds.download`.
  - Store the SDS file(s) on the `sds` media collection of the Material model (Spatie Media Library v11).
- GHS ピクトグラム
  - 一覧テーブルでは、モデルに `ghsImageUrls()` メソッドが存在する場合に、その返却 URL を表示します。未実装の場合は `N/A` と表示されます。
  - 画像は設定ファイルの `ghs.disk` / `ghs.directory` / `ghs.map` に従い、任意の拡張子（bmp/png/jpg）で配置できます。

PDF & QR/Scanning Notes

- Purchase Order PDF: `lastdino/chrome-laravel` によるレンダリングを想定しています。アプリ側で Chrome/Chromium 実行環境の準備が必要です。
- Receiving/Ordering のスキャンは JSON エンドポイントと Livewire 画面を提供します。認可はグループミドルウェアに準拠します。

Authorization

By default, all UI routes use `['web', 'auth']`. Adjust via `config('procurement_flow.middleware')`.

Customization

- Views/Translations: publish and override in your app.
- Route prefix & middleware: configurable.
- GHS images: supply/override via storage as per config.

Local Development (Monorepo)

- Package path: `packages/lastdino/procurement-flow`
- Provider: `Lastdino\ProcurementFlow\ProcurementFlowServiceProvider`
- After changes, if UI changes are not reflected, run `npm run dev` or `npm run build` in your host app.

Testing

- This repository uses Pest v4. Run focused tests where possible:

```
php artisan test
php artisan test tests/Feature/YourTest.php
php artisan test --filter=your_test_name
```

Coding Style

- Run Laravel Pint before committing code changes:

```
vendor/bin/pint --dirty
```

License

MIT License.
