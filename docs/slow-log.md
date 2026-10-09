# Slow query log

Ghi log query chậm và cảnh báo request/job có quá nhiều query (N+1). Log đi vào kênh log
thường của app, nên đọc ngay trong Log Viewer, kể cả log của host ở xa.

## Tích hợp

### 1. Bật

Package **mặc định tắt** slow log. Bật bằng env:

```
SLOW_LOG_ENABLED=true
```

hoặc publish config rồi sửa mặc định ngay trong code (nên làm khi muốn bật ở mọi host mà
không phải sửa `.env` từng máy):

```bash
php artisan vendor:publish --tag=slow-log-config
```

```php
// config/slow-log.php
'enabled' => env('SLOW_LOG_ENABLED', true),
'channel' => env('SLOW_LOG_CHANNEL', 'daily'),
```

File của app chỉ cần ghi key mình đổi, kể cả trong nhóm `trace` / `dedicated`: provider gộp
sâu thêm một cấp cho hai nhóm đó (Laravel `mergeConfigFrom` chỉ gộp cấp một).

Listener chỉ gắn ở các môi trường trong `environments` (mặc định `local`, `production`).
Không cần đăng ký provider: `LogViewerRemoteServiceProvider` tự register
`SlowLogServiceProvider`.

### 2. Ghi ra channel riêng (nên làm)

```
SLOW_LOG_CHANNEL=slow-log
```

Package tự khai channel `slow-log` (driver daily → `storage/logs/slow-log-YYYY-MM-DD.log`,
14 ngày) nếu app chưa có channel cùng tên; app đã khai thì giữ nguyên của app. Tách file riêng
thì đọc nhanh hơn hẳn (log chung có thể vài trăm MB/ngày), và về sau cấp quyền đọc cho công cụ
theo từng channel được.

Đổi tên, đường dẫn, số ngày, quyền file ở nhóm `dedicated`:

```php
'dedicated' => [
    'permission' => 0666, // web (www-data) và CLI (user deploy) cùng ghi một file
],
```

Key thiếu trong nhóm lấy của package. `channel` vẫn nhận tên một channel bất kỳ app đã khai;
null = kênh mặc định.

### 3. Bật tắt từng phần

| Phần | Key | Env | Mặc định | Khi nào tắt |
|---|---|---|---|---|
| Toàn bộ slow log | `enabled` | `SLOW_LOG_ENABLED` | `false` | — |
| Stack trace của query chậm | `trace.enabled` | `SLOW_LOG_TRACE` | `true` | Log quá dài |
| Quy Blade compiled về `.blade.php` | `trace.blade` | `SLOW_LOG_TRACE_BLADE` | `true` | Hiếm khi cần — muốn thấy chính file compiled |
| Quy cache Livewire 4 về `⚡component` | `trace.livewire` | `SLOW_LOG_TRACE_LIVEWIRE` | `true` | Livewire đổi cách đặt tên cache (xem [Livewire](#livewire)) |
| Vá Debugbar cho Livewire | `debugbar_livewire` | `SLOW_LOG_DEBUGBAR_LIVEWIRE` | `true` | Debugbar đổi nội bộ (xem [Debugbar](#vá-debugbar-cho-livewire)) |

App **không có** Livewire hay Debugbar thì không phải tắt gì: tầng Livewire thấy
`livewire.component_locations` rỗng nên tự thành no-op, bản vá Debugbar không thấy binding
`debugbar` nên không gắn listener. Cờ chỉ để tắt khi các phần đó **có mặt** mà gây phiền.

Gói vendor bị lọc khỏi trace khai ở `trace.skip_vendors` (mặc định `laravel/framework`,
`livewire/livewire`, `barryvdh/laravel-debugbar`). Frame trong chính package luôn bị lọc.

### 4. (Tuỳ chọn) Context request cho mọi dòng log

Logger tự gắn `req.url` và `req.referer` (đã che, xem [URL](#url-trong-nhãn-context)) vào
context của dòng log nó ghi. App nào muốn **mọi** dòng log của request đều có hai key này
thì tự chia sẻ bằng middleware, dùng chung helper che URL của package:

```php
use HocVT\LogViewerRemote\Support\LoggableUrl;

Log::shareContext([
    'req.url' => LoggableUrl::fromRequest($request),
    'req.referer' => LoggableUrl::fromString($request->header('referer')),
]);
```

Trùng tên key là có chủ đích: Laravel gộp context bằng `array_merge` nên hai nơi cùng ghi
chỉ ra một key. Route nằm ngoài nhóm middleware đó vẫn có key do logger tự gắn.

## Cách hoạt động

`SlowLogServiceProvider` đăng ký `SqlLogger` dạng singleton rồi lắng nghe:

| Event | Việc làm |
|---|---|
| `QueryExecuted` | Ghi nhận query; log ngay nếu chậm hơn ngưỡng |
| `RequestHandled` | Tổng kết + reset (context = 1 HTTP request) |
| `JobProcessing` / `JobProcessed` / `JobFailed` | Reset trước, tổng kết sau (context = 1 job) |
| `CommandFinished` | Tổng kết + reset (context = 1 artisan command) |

Mỗi **context** được tổng kết rồi reset độc lập. Đây là điểm bắt buộc: thiếu reset thì
`queue:work` và `schedule:run` sẽ cộng dồn số liệu và phình bộ nhớ vô hạn.

## Điều kiện log tổng kết

Log khi thoả **bất kỳ** điều nào (các ngưỡng độc lập, đặt 0 để tắt riêng từng cái):

- `total_to_log` — số query trong context vượt ngưỡng
- `total_ms_to_log` — tổng thời gian query vượt ngưỡng
- `duplicate_to_log` — một query lặp lại >= N lần → nghi ngờ N+1

Query được gom nhóm theo SQL đã chuẩn hoá (gộp `?, ?, ?, ...` thành `?, ...`, bỏ khoảng
trắng thừa) kèm tên connection, sắp xếp theo số lần lặp giảm dần:

```
[WEB][1.2.3.4][https://.../my/profile] Quá nhiều query [gt50]: 63 query + Nghi ngờ N+1: 1 query lặp 41x
  63 query / 812ms / 9 query khác nhau
    x41     402ms [crdb] select * from vocabularies where "id" = ?
             96ms [crdb] select * from videos where "id" in (?, ...)
```

Cột `xN` và cột ms luôn cách nhau ít nhất một dấu cách, kể cả khi số dài hơn cột
(`x10000  100000ms`); định dạng cũ `%4s%6s` từng dính thành `x10000100000ms`.

Dòng query chậm ghi ở mức `level` (mặc định `alert`), dòng tổng kết luôn là `warning`.
Job và command có nhãn `[CLI][tên job / lệnh]` — cả dòng query chậm: tên được gắn từ
`JobProcessing` / `CommandStarting`, job ưu tiên hơn command (`queue:work` chạy job nào thì
ra tên job đó).

### Context cho máy đọc

Phần chữ để người đọc; công cụ (bộ đọc của agent) đọc context JSON ở cuối dòng:

| Key | Có ở | Ý nghĩa |
|---|---|---|
| `slow_log` | cả hai | `query` (một query chậm) hoặc `summary` (tổng kết context) |
| `ms`, `connection` | query | thời gian và connection của query chậm |
| `reasons` | summary | mã lý do: `total`, `total_ms`, `duplicate` |
| `total`, `total_ms`, `total_class`, `unique_queries`, `worst_duplicate` | summary | số liệu context |
| `cli.context` | job / command | tên job hoặc lệnh |
| `req.url`, `req.referer` | web | URL đã che (xem dưới) |
| `req.route` | web | URI template của route (`/video/{id}/{slug?}`) — gom theo trang không phải đoán từ URL |

Đổi định dạng thì đổi cả parser của agent; app dùng package nên có test khớp format.
Request Livewire đều là `/livewire/update`: trang thật nằm ở `req.referer`.

## Đọc log

- Lọc trong Log Viewer: `Nghi ngờ N+1`, `Quá nhiều query`, `Tổng thời gian query`, hoặc
  theo `req.url` trong context.
- Dòng query chậm là **đuôi phân phối đã bị cắt** ở `time_to_log`: đếm **số lượt**, đừng lấy
  trung bình latency. Latency "phẳng" quanh ngưỡng chỉ là mép ngưỡng; số lượt tăng vọt trong
  khi latency đứng yên là bão hoà do khối lượng (N+1, thiếu cache), không phải query plan tồi.

## Bảo mật

### Giá trị binding

Mặc định log **giữ placeholder `?`**, không ghi giá trị binding nào.

`SLOW_LOG_RAW_BINDINGS=true` in thêm dòng `-> ` là SQL có giá trị thật để copy chạy lại.
Cờ này **chỉ có tác dụng khi `APP_ENV=local`**: ở môi trường khác logger bỏ qua nó mà
không báo gì, nên lỡ bật trên production cũng không lọt dữ liệu.

Kể cả ở local, giá trị vẫn được che bằng ba lớp:

| Lớp | Quy tắc | Ví dụ |
|---|---|---|
| Bảng nhạy cảm | Query đụng `jobs`, `failed_jobs`, `job_batches`, `sessions`, `cache`, `cache_locks`, `personal_access_tokens`, `password_reset_tokens`, `password_resets` → không in dòng `-> ` | payload job chứa model đã serialize |
| Hình dạng giá trị | Hash bcrypt/argon → `'[hash]'`; dài hơn 64 ký tự → `'[812 chars]'`; chuỗi toàn chữ + số, có cả hai, từ 24 ký tự → `'[token 60 chars]'`; binary/null byte → `'[binary 9 bytes]'` | `password`, `remember_token`, token OAuth/FCM |
| Giữ nguyên | Số, ngày, bool, chuỗi ngắn (email, slug, id YouTube) | để còn tái hiện được N+1 |

Không che theo **tên cột** là có chủ đích. Binding đi theo vị trí, muốn biết `?` thứ mấy là
cột `password` phải parse SQL, và cách đó vỡ với upsert, subquery hay raw. Hình dạng giá trị
thì không phụ thuộc câu SQL. Đổi lại, OTP 6 số hay mật khẩu plaintext ngắn không bị che,
nên dòng `-> ` vẫn chỉ dùng để debug, không gửi đi đâu.

`toRawSql()` gặp binding binary (file nén) sẽ ném `RuntimeException`; nếu để lọt ra listener
`QueryExecuted` thì hỏng luôn request đang chạy. Logger nuốt mọi lỗi dựng SQL thô và quay về
SQL có `?`.

### URL trong nhãn context

Nhãn `[WEB][ip][url]` và key `req.url` / `req.referer` dùng
`HocVT\LogViewerRemote\Support\LoggableUrl` thay cho `fullUrl()`, vì nhiều URL mang bí mật
ngay trên đó:

```
/affiliate/s/aB3…(40 ký tự)                → /affiliate/s/{stats_token}
/auth?tab=…&token=…&email=…                → /auth?tab=…&token=***&email=***
/webhooks/baokim/success?signature[…]=…    → /webhooks/baokim/success?signature=***
/video/123/shape-of-you                    → giữ nguyên
```

- **Path**: tham số route có tên nhạy cảm giữ nguyên dạng template `{stats_token}`, các tham
  số khác (id, slug) giữ giá trị thật.
- **Query**: che giá trị của key nhạy cảm, kể cả key lồng nhau.
- **Tên nhạy cảm**: tên key tách theo snake_case chứa một trong `token`, `secret`,
  `password`, `signature`, `checksum`, `otp`, `email`, `session`, `cookie`, `jwt`… Các từ quá
  chung chung (`code`, `auth`, `api_key`…) chỉ khớp khi là **nguyên tên**, để
  `language_code` hay `{key?}` không bị che.
- **Referer** không dò được route (xem comment `fromString()`), nên path của referer chỉ che
  theo luật "trông như token", và fragment `#…` bị bỏ.

SQL dài bị cắt ở `max_sql_length`, số nhóm giữ trong bộ nhớ trần ở `max_groups`.

## Truy vết

Với query chậm, logger in stack trace đã lọc nhiễu (`trace.skip_vendors`) và quy đổi file
compiled về file nguồn. Phần quy đổi nằm ở `CompiledViewResolver`, hai tầng bật tắt riêng.

### Blade

`storage/framework/views/<hash>.php` → đọc marker `PATH` Blade ghi ở cuối file (512 byte
cuối). Thư mục lấy từ `config('view.compiled')`.

### Livewire

Livewire 4 biên dịch hai tầng nên stack trace không chỉ thẳng vào file gốc:

```
⚡component.blade.php
  ├─ storage/framework/views/livewire/classes/<hash8>.php      (phần PHP, nơi mount() chạy)
  └─ storage/framework/views/livewire/views/<hash8>.blade.php  (phần template)
       └─ storage/framework/views/<xxh128>.php                 (Blade compile tiếp)
```

`<hash8>` = `substr(md5(đường dẫn tương đối so với base_path), 0, 8)` — với **SFC** là
đường dẫn file `⚡*.blade.php`, với **MFC** là đường dẫn *thư mục* `⚡tên-component`. Resolver
quét `config('livewire.component_locations')` dựng bảng ngược một lần mỗi process (lazy).

Công thức này chép từ `Livewire\Compiler\CacheManager` — **nội bộ của Livewire**. Nâng
Livewire mà trace hiện lại `storage/framework/views/livewire/...` thì so lại hàm hash ở đó;
trong lúc chờ sửa, tắt `trace.livewire`.

### Chi phí

Tắt bằng `SLOW_LOG_TRACE=false` nếu thấy tốn — nhưng chi phí đo được là không đáng kể:

| Thao tác | Chi phí |
|---|---|
| 1 round-trip query tới DB | ~20,9 ms |
| `record()` mỗi query | 0,0015 ms (0,007%) |
| `findSource()` mỗi query chậm | 0,0026 ms |
| Dựng bảng hash Livewire | 0,85 ms, **1 lần/process**, lazy |
| Tra bảng sau khi cache | 0,0001 ms |

## Vá Debugbar cho Livewire

`DebugbarLivewireSource` sửa lỗi Debugbar hiển thị hash thô thay vì tên component Livewire.

Nguyên nhân: `QueryCollector::parseTrace()` thấy frame nằm trong `storage_path()` thì lấy tên
file làm hash rồi tra `findViewFromHash()`, vốn chỉ so với hash **xxh128 32 ký tự** của Blade.
File cache Livewire lại đặt tên bằng **md5 8 ký tự** — không bao giờ khớp, nên rơi vào nhánh
`else` và in hash thô. Đây không phải lỗi config; phép tính hash của Debugbar cho view Blade
thường là đúng.

Cách vá: thay vì thay cả `QueryCollector` (phải chép lại ~130 dòng `__invoke` của
`DatabaseCollectorProvider`), chỉ nghe `QueryExecuted` **sau** Debugbar rồi sửa lại frame vừa
ghi. Listener gắn trong `app()->booted()`, nên luôn đứng sau listener Debugbar gắn trong
`boot()` của nó, bất kể thứ tự nạp provider.

Bề mặt phụ thuộc vào nội bộ Debugbar chỉ gồm: property `queries` và frame có `name`/`file`.
Nếu nâng cấp Debugbar mà tên đổi, `patch()` nuốt exception nên không ảnh hưởng request — chỉ
là hash lại hiện ra như cũ. Khi đó tắt `debugbar_livewire` cho tới khi sửa.

Chỉ chạy khi `APP_ENV=local` và có binding `debugbar`; độc lập với `enabled`.

## Config

Đầy đủ ở `config/slow-log.php` của package — mỗi key có chú thích tại chỗ.

## Test

Chưa có test trong package (cần orchestra/testbench). Test tích hợp nằm ở project dùng
package: `tests/Feature/SqlLog/`, `tests/Unit/LoggableUrlTest.php`.
