# hocvt/log-viewer-remote

Mở rộng [opcodesio/log-viewer](https://github.com/opcodesio/log-viewer) cho mô hình
"một Log Viewer xem log của nhiều host":

1. **Auth thống nhất.** Request server-to-server giữa các host đi bằng
   `Authorization: Bearer <shared secret>`. Người dùng thật thì hỏi Gate `viewLogViewer`.
2. **Tải file log của host ở xa.** Package gốc không tải được: nút Download gọi XHR sang
   origin khác (CORS chặn), và route download bên đó vẫn có `AuthorizeLogViewer` nên
   trình duyệt không có session cũng 403. Package viết lại `download_url` về origin
   hiện tại rồi tải hộ server-to-server, stream về client.
3. **Bớt cấu hình tay.** Hosts khai bằng env, `api_stateful_domains` mặc định theo `APP_URL`.
4. **Slow query log.** Log query chậm và cảnh báo request/job có quá nhiều query (N+1),
   che sạch bí mật trong SQL lẫn URL, truy vết về đúng file Blade / component Livewire.
   Mặc định tắt — xem [Slow query log](#slow-query-log).
5. **Agent phân tích log từ xa.** Lệnh `log-viewer-remote:aggregate` / `:entries` gom số liệu
   (lỗi, trang chậm, N+1, job tốn DB…) ngay trên host có file, không tải file về; token chỉ đọc,
   giới hạn theo channel — xem [Agent](#agent-phân-tích-log-từ-xa).

## Cài

Trên **mọi** host (host xem và host bị xem):

```bash
composer require hocvt/log-viewer-remote
php artisan log-viewer-remote:secret     # sinh LOG_VIEWER_SHARED_SECRET, copy sang các host khác
```

Trên host **xem** log, thêm vào `.env`:

```
LOG_VIEWER_HOSTS="web=https://app.example.com/log-viewer,api=https://api.example.com/log-viewer"
```

Trên host chạy **production**, mở quyền xem trong `AppServiceProvider::boot()`:

```php
Gate::define('viewLogViewer', fn (?User $user): bool => (bool) $user?->isAdmin());
```

Mặc định của package: `local` cho qua, môi trường khác chặn hết.

### App không dùng Gate được

Có app mà mọi lần gọi Gate với user đã đăng nhập đều ném `TypeError`: cài
`spatie/laravel-permission` (nó đăng ký một `Gate::before` nhận
`Illuminate\Contracts\Auth\Access\Authorizable`) trong khi model `User` không
implement contract đó. Thường gặp ở codebase cũ tự làm tầng phân quyền riêng và đã có
sẵn `User::can()` với ngữ nghĩa khác, nên không thể implement `Authorizable` mà không
làm gãy chỗ khác.

Những app đó bỏ qua tầng Gate bằng cách cắm callback riêng, đường shared secret giữa
các host vẫn giữ nguyên:

```php
use HocVT\LogViewerRemote\LogViewerRemote;

LogViewerRemote::authorizeUsing(
    fn (Request $request): bool => app()->isLocal() || access()->hasRoles(['Administrator'])
);
```

Cắm callback thì Gate `viewLogViewer` không còn được hỏi tới.

Kiểm tra kết nối:

```bash
php artisan log-viewer-remote:check
```

## Đổi prefix mặc định

**Nên đổi.** Mặc định Log Viewer nằm ở `/log-viewer` — ai cũng đoán được, và trang này
phơi toàn bộ log ứng dụng. Gate chặn người lạ, nhưng một URL không đoán được sẽ cắt
luôn lượt dò của bot trước khi chạm tới tầng xác thực. Publish config rồi đổi:

```bash
php artisan vendor:publish --tag=log-viewer-config
```

```php
// config/log-viewer.php
'route_path' => 'sys/x9f2logs',   // nhiều đoạn cũng được
```

`route_path` không đọc env, nên bắt buộc publish config mới đổi được. Đổi xong nhớ
`php artisan optimize:clear` (hoặc `route:cache` lại nếu đang cache route).

Toàn bộ route đi theo prefix mới, kể cả hai route của package này, và thứ tự vẫn đúng
— chúng vẫn đứng trước route bắt-tất `{prefix}/{view?}`.

**Ba điều cần nhớ khi đã đổi:**

1. **Prefix của mỗi host là độc lập.** Host A xem log host B thì chỉ cần URL trong
   `LOG_VIEWER_HOSTS` trỏ đúng gốc Log Viewer của B. Hai bên đặt prefix khác nhau vẫn chạy.
2. **Khai host phải kèm prefix.** Chỉ URL trần mới được tự bù, và giá trị bù là
   `route_path` của chính host đang khai — tức đoán cả cụm dùng chung quy ước:

   ```
   LOG_VIEWER_HOSTS="a=https://a.example.com/sys/x9f2logs,b=https://b.example.com"
   ```

   `b` sẽ thành `https://b.example.com/<route_path của host này>`. Nếu `b` đặt prefix
   khác, phải viết đủ. Sai prefix thì `log-viewer-remote:check` báo `404 — sai route_path`.
3. **Đừng hardcode `/log-viewer` trong test hay link.** Dùng
   `config('log-viewer.route_path')`, không thì đổi prefix là gãy hàng loạt.

## Cấu hình

`php artisan vendor:publish --tag=log-viewer-remote-config` nếu cần đổi timeout. Các key:

| Key | Env | Ý nghĩa |
|---|---|---|
| `shared_secret` | `LOG_VIEWER_SHARED_SECRET` (fallback `LOG_VIEWER_PRODUCTION_TOKEN`) | Bearer token giữa các host |
| `hosts` | `LOG_VIEWER_HOSTS` | `id=url,id2=url2`; merge vào `log-viewer.hosts`, không ghi đè host đã khai tay |
| `timeout.request` / `timeout.download` | — | Giây, gọi sang host xa |

Host cần tên đẹp, basic auth hay header riêng thì khai trong `config/log-viewer.php`
như bình thường; package chỉ điền những id chưa có.

## Slow query log

```
SLOW_LOG_ENABLED=true
```

Không cần đăng ký thêm provider. Phần truy vết Blade, Livewire và bản vá Debugbar có cờ bật
tắt riêng (`trace.blade`, `trace.livewire`, `debugbar_livewire`); app không có Livewire hay
Debugbar thì các phần đó tự thành no-op. Publish config:
`php artisan vendor:publish --tag=slow-log-config`.

Tài liệu đầy đủ (tích hợp, ngưỡng, cách che dữ liệu, đọc log): [docs/slow-log.md](docs/slow-log.md).

## Agent phân tích log từ xa

```bash
php artisan log-viewer-remote:secret --agent     # một lần, copy LOG_VIEWER_AGENT_TOKEN sang mọi host
php artisan log-viewer-remote:check              # cột Agent: phiên bản + channel cho phép của từng host
php artisan log-viewer-remote:aggregate --host=m1 --channel=slow-log --date=2026-10-05
php artisan log-viewer-remote:entries --host=m1 --at=slow-log-2026-10-05.log@1124892
```

Host bị đọc khai channel được phép (`LOG_VIEWER_AGENT_CHANNELS=slow-log,daily`; bỏ trống = chỉ
slow log). Agent token chỉ gọi được `api/agent/*` — không tải / xoá file, không vào UI. Skill
cho Claude Code: `php artisan vendor:publish --tag=log-viewer-remote-skill`.

Tài liệu đầy đủ (bảy thao tác hay làm, cách đọc số, HTTP API, cấu hình): [docs/agent.md](docs/agent.md).

## Những chỗ dễ làm hỏng khi sửa

- **Host trả ra ngoài không có credential.** Vendor đưa nguyên `LogViewer::getHosts()` vào
  `window.LogViewer` và trả cả `auth` ở `/api/hosts` — ai xem được Log Viewer cũng đọc được
  shared secret trong mã nguồn trang. Package bỏ `auth` / `headers` qua
  `LogViewer::resolveHostsUsing()`; credential thật chỉ đọc qua `Support\HostCredentials`
  (thẳng từ `config('log-viewer.hosts')`), gắn vào request qua `Support\RemoteHttp`. Vendor chỉ
  giữ **một** resolver: app tự gọi `resolveHostsUsing()` là ghi đè mất bản vá này.
- **Forward tự làm, không gọi vendor.** `ForwardRequestToHost` không gọi `parent::handle()` vì
  bản vendor đọc credential từ `$host->auth` (đã rỗng). Đường forward giữ như vendor (URL, header
  `X-Forwarded-*`, không gửi body), thêm timeout `timeout.forward` và chép `Retry-After`.

- **Route nạp ở `register()`, không ở `boot()`.** Package gốc đăng ký route bắt-tất
  `log-viewer/{view?}` (`where '.*'`) trong `boot()`; mọi route dưới `/log-viewer` khai
  sau nó đều bị nuốt.
- **Thay middleware vendor bằng container binding.** `ForwardRequestToHostMiddleware`
  được gắn cứng trong `routes/api.php` của package gốc; Pipeline resolve middleware bằng
  `container->make()` nên `bind()` là đủ.
- **Không gửi `X-Forwarded-Host` khi xin link ký.** Host xa phải ký URL bằng domain của
  chính nó, nếu không chữ ký hỏng khi gọi lại.
- **Stream theo chunk, không `->body()`.** Log production có thể vài trăm MB.
- **Gate mặc định định nghĩa trong `boot()` của package.** App provider boot sau nên
  `Gate::define` trong `AppServiceProvider` ghi đè được.

## Gỡ rối

**File tải về bị chèn rác ở đầu (vài byte khoảng trắng), md5 không khớp.**
App có output lọt ra trước response — thường là file PHP có khoảng trắng trước `<?php`
hoặc sau `?>`, và file đó được nạp ở mọi request (route file, config, helper). Rác đó
chui vào đầu MỌI response tải file của app, không riêng Log Viewer. Tìm bằng:

```bash
php -r '$b=file_get_contents($argv[1]); exit(str_starts_with($b,"<?php")?0:1);' <file>
```

Đi qua proxy thì rác nhân đôi, vì cả host xem lẫn host xa đều thêm phần của mình.

**UI 403 toàn bộ API.** `EnsureFrontendRequestsAreStateful` chưa nhận domain hiện tại.
Package tự điền theo `APP_URL`; nếu truy cập bằng domain khác thì khai
`LOG_VIEWER_API_STATEFUL_DOMAINS`.

**`log-viewer-remote:check` báo 403.** Token hai bên lệch, hoặc host xa chưa cài package.

**Identifier file khác nhau giữa CLI và web.** Identifier là hash của đường dẫn tuyệt
đối, mà CLI và php-fpm có thể resolve `storage_path()` khác nhau. Luôn lấy identifier
từ cùng tiến trình sẽ tải — luồng UI vốn đã đúng, chỉ sai khi copy tay từ `tinker`.

**Mặc định package gốc quét cả `/var/log/nginx/*` và vài đường dẫn hệ thống.** Nếu không
muốn, publish `config/log-viewer.php` rồi thu hẹp `include_files`.

## Test

Phần cần Laravel chưa có test trong package (cần orchestra/testbench). Test tích hợp nằm ở project
dùng package: `tests/Feature/LogViewer/`, `tests/Feature/SqlLog/`, `tests/Unit/LoggableUrlTest.php`.

Phần PHP thuần (bộ đọc log của agent, `src/Agent/Scan`, `src/Agent/ChannelFiles.php`) có unit
test ngay trong package: `composer install && composer test`, hoặc từ app đang dùng package:
`vendor/bin/phpunit -c <đường dẫn package>/phpunit.xml`.
