# Glitter Social Login

Glitter.kr이 유지보수하는 Gnuboard7 플러그인입니다. Naver, Kakao, Google, Facebook, GitHub 계정으로 로그인하거나 소셜 회원가입을 진행하고, 마이페이지에서 계정을 연결·해제할 수 있습니다.

## 지원 환경

| 항목 | 내용 |
| --- | --- |
| Plugin | Glitter Social Login |
| Version | 1.5.1 (`plugin.json` 기준) |
| Identifier | `glitter-social_login` |
| Vendor | Glitter.kr |
| Gnuboard7 | `>=7.0.10` |
| PHP | `^8.2` |
| UI Language | 한국어, 영어 |
| License | MIT |

## 주요 기능과 1.5.1 변경 사항

- 다섯 OAuth 제공자의 로그인·조건부 신규 가입과 인증된 회원의 수동 계정 연결·해제를 제공합니다.
- 로그인 대상 회원은 활성 상태와 계정 잠금 여부를 확인합니다. OAuth callback 이후 최종 토큰 교환 시점에도 회원을 다시 조회해 검사합니다. 일반 비밀번호 로그인과 모든 인증 절차가 동일하다는 의미는 아닙니다.
- 미연결 계정은 callback에서 즉시 회원으로 만들지 않습니다. 가입 대기 상태에서 회원정보·이용약관·개인정보 동의를 받은 뒤, 적용되는 가입 본인확인 정책을 확인하고 회원과 소셜 계정 연결을 하나의 DB transaction으로 생성합니다. OAuth 제공자 동의는 Gnuboard7 회원가입 동의나 본인확인을 대신하지 않습니다.
- 기존 회원 자동 연결과 수동 연결의 중복·충돌 처리를 보강하고, 소셜 전용 회원이 마지막 로그인 수단을 해제하지 못하도록 보호합니다.
- 최종 bearer token을 URL에 직접 넣지 않고, 시작한 브라우저 세션에 묶인 60초짜리 일회용 교환코드를 사용합니다. DB에는 코드 원문이 아니라 hash를 저장하며, 교환은 한 번만 성공할 수 있도록 처리합니다.
- 알려진 로그인·회원가입 레이아웃 구조의 UI 삽입을 보강하고 중복 삽입을 피합니다. 모든 제3자 템플릿에 버튼이 표시된다는 보장은 없습니다.

제공자 표시 순서는 1.5.0에서 추가되어 1.5.1에서도 유지됩니다. 2FA 계정의 소셜 로그인 제한은 아래 **현재 제한 사항**을 확인하세요.

## Routes

모든 경로의 공통 prefix는 `/api/plugins/glitter-social_login`입니다. `{provider}`에는 `naver`, `kakao`, `google`, `facebook`, `github`만 사용할 수 있습니다.

| Purpose | Route |
| --- | --- |
| Naver redirect | `GET /api/plugins/glitter-social_login/naver/redirect` |
| Naver callback | `GET /api/plugins/glitter-social_login/naver/callback` |
| Kakao redirect | `GET /api/plugins/glitter-social_login/kakao/redirect` |
| Kakao callback | `GET /api/plugins/glitter-social_login/kakao/callback` |
| Google redirect | `GET /api/plugins/glitter-social_login/google/redirect` |
| Google callback | `GET /api/plugins/glitter-social_login/google/callback` |
| Facebook redirect | `GET /api/plugins/glitter-social_login/facebook/redirect` |
| Facebook callback | `GET /api/plugins/glitter-social_login/facebook/callback` |
| GitHub redirect | `GET /api/plugins/glitter-social_login/github/redirect` |
| GitHub callback | `GET /api/plugins/glitter-social_login/github/callback` |
| OAuth exchange | `POST /api/plugins/glitter-social_login/exchange` |
| 추가 본인확인 제출 | `POST /api/plugins/glitter-social_login/two-factor` |
| 가입 정보 제출 | `POST /api/plugins/glitter-social_login/registration` |
| 가입 본인확인 완료 | `POST /api/plugins/glitter-social_login/registration/idv` |
| 연결 계정 조회 | `GET /api/plugins/glitter-social_login/accounts` |
| 수동 연결 준비 | `POST /api/plugins/glitter-social_login/{provider}/link/prepare` |
| 연결 해제 | `DELETE /api/plugins/glitter-social_login/{provider}/unlink` |

계정 조회·수동 연결·해제는 인증된 회원에게만 열립니다. OAuth 개발자 콘솔에는 사이트의 실제 HTTPS 주소로 해당 callback URI를 등록해야 합니다.

- Naver: `https://your-domain.com/api/plugins/glitter-social_login/naver/callback`
- Kakao: `https://your-domain.com/api/plugins/glitter-social_login/kakao/callback`
- Google: `https://your-domain.com/api/plugins/glitter-social_login/google/callback`
- Facebook: `https://your-domain.com/api/plugins/glitter-social_login/facebook/callback`
- GitHub: `https://your-domain.com/api/plugins/glitter-social_login/github/callback`

## Settings

관리자 → 플러그인 → 소셜 로그인 설정(`/admin/plugins/glitter-social_login/settings`)에서 제공자별 사용 여부와 Client ID·Client Secret을 입력합니다. Facebook은 Meta for Developers의 App ID·App Secret을 사용합니다. Naver Login API 등 각 제공자의 로그인 기능을 활성화하고 위 callback URI를 등록해야 합니다.

버튼 표시 여부는 프런트 설정의 다음 활성화 값에서 읽습니다. Client ID·Secret은 프런트 전역 설정에 노출하지 않으며, 관리자 설정 응답에서는 secret을 마스킹합니다. **사용 설정만 켜고 자격 증명을 입력하지 않으면 버튼이 보여도 OAuth는 시작되지 않습니다.**

```text
_global.plugins['glitter-social_login'].naver_enabled
_global.plugins['glitter-social_login'].kakao_enabled
_global.plugins['glitter-social_login'].google_enabled
_global.plugins['glitter-social_login'].facebook_enabled
_global.plugins['glitter-social_login'].github_enabled
```

버튼과 연결 계정 목록의 기본 표시 순서는 Naver, Kakao, Google, Facebook, GitHub입니다. 관리자 순서 제어는 사용 설정된 제공자가 둘 이상일 때만 보입니다. 순서는 표시만 바꾸며 제공자 사용 여부, 자격 증명, OAuth 경로를 바꾸지 않습니다.

활성 제공자를 드래그하거나 위·아래 버튼으로 이동할 수 있습니다. 비활성 제공자는 저장된 전체 순서의 위치를 유지합니다. 이 순서는 로그인·회원가입·마이페이지 계정 관리 화면에 적용됩니다.

## 계정 식별과 이메일 자동 연결

소셜 계정의 기본 식별자는 이메일이 아니라 `(provider, provider_user_id)`입니다. 이미 연결된 계정은 이 조합으로 찾습니다. 같은 이메일이라는 이유만으로 다른 소셜 계정이나 기존 Gnuboard7 회원을 무조건 합치지 않습니다.

처음 보는 소셜 계정을 이메일로 기존 회원에게 자동 연결하려면 **제공자별로 사용 가능한 이메일 조건**과 **기존 Gnuboard7 회원의 이메일 인증 상태**를 모두 만족해야 합니다. 기존 회원의 이메일이 미인증이면 자동 연결하지 않습니다. Facebook 이메일은 이 자동 매칭에 사용하지 않습니다. 자동 연결이 이루어진 경우 해당 회원에게 알림을 보내는 설정이 등록되어 있습니다.

| 제공자 | 소셜 ID와 기존 회원 이메일 매칭 정책 |
| --- | --- |
| Naver | 성공한 프로필 응답의 `response.id`를 ID로 사용합니다. 유효한 `response.email`을 프로필 응답과 대조해 사용할 수 있으며, Naver에 없는 Google식 `email_verified` 값을 만들어 판단하지 않습니다. |
| Kakao | `is_email_valid`와 `is_email_verified`가 모두 `true`인 이메일만 사용합니다. |
| Google | 제공자 응답의 `email_verified`를 확인한 이메일만 사용합니다. |
| Facebook | 소셜 ID로 이미 연결된 계정은 찾지만, Facebook 이메일로 기존 회원을 자동 연결하지 않습니다. |
| GitHub | Socialite가 `user:email` 권한으로 조회한 primary·verified 이메일만 사용합니다. |

제공자에게서 사용할 수 있는 이메일을 얻지 못한 미연결 계정도 가입 대기 화면으로 이동할 수 있습니다. **최종 신규 가입에는 유효한 이메일이 필요**하며, 이 경우 사용자가 직접 입력해야 합니다. 이메일 없이 회원 생성이 완료되는 기능은 아닙니다.

## 소셜 회원가입과 본인확인

가입 대기 화면에서 이름, 이메일, 선택 닉네임·언어를 받고 이용약관 및 개인정보 처리방침에 별도로 동의받습니다. 제공자가 사용 가능한 이메일을 준 경우 가입 과정에서 다른 이메일로 바꿀 수 없습니다. 동의와 필요한 검증을 마치기 전에는 새 회원을 만들지 않습니다.

사이트에 가입 **전** 본인확인 정책이 적용되면 Gnuboard7의 Signup-purpose 본인확인을 거친 뒤 가입합니다. 가입 **후** 본인확인이 필요한 정책이나 신규 소셜 회원에게 전역 2FA가 적용되는 설정에서는 현재 신규 소셜 가입을 완료하지 않고 차단합니다. OAuth 제공자 인증과 Gnuboard7 본인확인은 별개입니다.

소셜 전용 신규 회원의 비밀번호 컬럼에는 사용자가 알지 못하는 임의 값의 hash를 저장하고, 플러그인에서 실제 비밀번호 미보유 상태를 별도로 관리합니다. 이 내부 값이 사용자가 설정한 일반 로그인 비밀번호를 뜻하지는 않습니다.

## 계정 수동 연결과 해제

로그인한 회원은 마이페이지 프로필에서 사용 설정된 제공자를 연결하거나 해제할 수 있습니다. 연결을 시작할 때 발급한 짧은 수명의 일회용 nonce를 브라우저 세션에 보관하고, OAuth callback에서 연결 대상과 제공자를 확인합니다. 이미 같은 회원에게 연결된 소셜 ID는 중복 행을 만들지 않고 처리하며, 다른 회원에게 연결된 ID는 가져올 수 없습니다.

해제는 본인 계정의 연결만 대상으로 합니다. 소셜 전용 회원에게 실제 비밀번호가 없고 연결된 계정이 하나뿐이라면 마지막 로그인 수단이 사라지지 않도록 해제를 거부합니다. 비밀번호를 설정한 회원은 해당 제한에서 제외됩니다.

## OAuth와 로그인 교환

OAuth `state`는 Socialite의 세션 기반 검증을 사용합니다. 플러그인이 별도로 만드는 브라우저 세션 결합값은 `state`를 대체하는 것이 아니라 callback 이후 일회용 교환코드를 다른 세션에서 사용하지 못하도록 하기 위한 것입니다. 로그인 후 이동할 주소는 사이트 내부의 루트 상대경로만 허용하며, 외부 절대 URL이나 `//` 형태의 URL 등은 기본 경로 `/`로 바꿉니다.

OAuth 시작·callback, 최종 교환 및 계정 연결·해제 요청에는 각각 rate limit이 적용됩니다. 최종 bearer token은 브라우저가 교환 API를 호출할 때 응답으로 받으며, callback의 URL query에는 토큰 대신 짧은 수명의 불투명한 교환코드만 들어갑니다. 교환코드는 시작한 브라우저 세션에 묶이고, 만료되거나 한 번 사용되면 다시 사용할 수 없습니다.

### 현재 제한 사항

- **2FA가 필요한 기존 회원의 소셜 로그인:** 현재 1.5.1 소스에는 추가 본인확인 화면과 검증 경로가 있지만, 검증 후 발급한 교환코드의 최종 소비 단계에서 2FA 필요 여부를 다시 확인해 bearer token 발급을 거부하는 경로가 있습니다. 따라서 2FA 계정의 소셜 로그인 완료를 지원 기능으로 간주하지 마세요. 해당 회원에게는 일반 로그인 경로를 안내하세요.
- 가입 후 본인확인 정책과 신규 소셜 회원에게 전역 2FA가 적용되는 설정에서는 신규 소셜 가입을 차단합니다.
- 알 수 없는 제3자 템플릿 구조에서는 버튼·계정 관리 위젯이 삽입되지 않을 수 있습니다. 이 경우 기존 페이지 구조를 그대로 둡니다.

## 화면 및 캐시 호환성

`core.layout_extension.after_apply` 훅을 사용해 알려진 `auth/login`, `auth/register`, `mypage/profile` 구조에 위젯을 삽입하고 중복 삽입을 피합니다. 마이페이지 프로필 편집에서는 소셜 전용 회원의 비밀번호 확인 화면 흐름도 알려진 구조에 한해 조정합니다. Core나 템플릿 파일은 직접 수정하지 않지만, 모든 제3자 템플릿 구조를 자동 인식하지는 않습니다.

소셜 로그인 설정 저장이 성공하면 활성 사용자 템플릿의 `auth/login`, `auth/register`, `mypage/profile` 레이아웃 캐시를 무효화합니다. 무효화가 실패하면 설정 저장은 유지되고 이전 UI가 남을 수 있으므로 운영자는 적용 후 화면을 확인해야 합니다.

제공자 아이콘은 플러그인에 포함된 로컬 이미지(Naver·Google·Facebook·GitHub SVG, Kakao PNG)를 data URI로 표시합니다. 별도 외부 이미지 서버에 hotlink하지 않습니다. 한국어와 영어 UI 번역을 포함합니다.

## 설치 및 업데이트

이 소스의 플러그인 식별자는 `glitter-social_login`이고 bundled source 디렉터리는 `plugins/_bundled/glitter-social_login`입니다. 공개 GitHub 저장소·`v1.5.1` Release·배포 ZIP 파일명은 이 프로젝트의 Git remote와 현재 소스만으로 확인되지 않았으므로 미확인 다운로드 링크를 싣지 않습니다. 외부 배포물을 사용할 때는 운영자가 실제 Release, 파일명, `plugin.json`의 식별자·버전을 먼저 확인하세요.

프로젝트에 bundled source가 제공된 경우, 운영 승인과 백업 확인 후 프로젝트 루트에서 Gnuboard7의 공식 플러그인 lifecycle을 사용합니다. 다음은 **실행 예시이며 자동 배포 절차가 아닙니다.** 기존 설치본의 사용자 수정 레이아웃을 보존할지 등은 적용 전에 확인하세요.

```bash
# 신규 설치
/usr/local/bin/php83 artisan plugin:install glitter-social_login --vendor-mode=auto

# 설치된 플러그인을 bundled source로 업데이트
/usr/local/bin/php83 artisan plugin:update glitter-social_login --source=bundled --vendor-mode=auto --layout-strategy=keep
```

동일 manifest 버전의 소스를 다시 적용할 필요가 있는 경우에만 변경 내용과 운영 영향을 검토한 뒤 `plugin:update`의 `--force` 옵션 사용 여부를 결정하세요. 확장을 단순 파일 복사로 적용하거나 활성 플러그인 디렉터리·생성된 hook/cache 파일을 직접 편집하지 마세요.

이 플러그인은 Socialite 등의 PHP 의존성이 필요합니다. 배포 소스는 raw `vendor/` 대신 `vendor-bundle.json`과 `vendor-bundle.zip`을 제공하며, G7의 vendor bundle lifecycle이 이를 설치하는 구조입니다. 공식 install/update lifecycle은 migration, vendor, 경로·hook·autoload, 레이아웃·캐시 반영과 관련됩니다. 1.5.1에서는 일회용 교환코드용 DB 테이블도 필요합니다. 운영 적용 중 코드와 migration이 전환되는 동안 요청이 섞이지 않도록 유지보수 시간과 트래픽 제어를 검토하고, 적용 후 기존 계정 로그인·교환 경로를 확인하세요.

## 기존 설정과 데이터

Gnuboard7은 플러그인 설정을 식별자별로 저장합니다. 예전 식별자에서 `glitter-social_login`으로 이전하는 경우 설정이 자동 복사된다고 가정하지 말고, 운영 절차에 따라 제공자 사용 여부와 자격 증명을 확인해야 합니다. 실제 secret 값을 문서나 로그에 기록하지 마세요.

기존 소셜 계정 데이터가 있는 설치를 위해 레거시 테이블 rename migration이 포함되어 있습니다. 기존 데이터 보존과 신규 교환 테이블 적용 여부는 공식 플러그인 lifecycle 결과로 확인해야 합니다.

## 버전별 주요 변화

- **1.5.1:** 로그인 eligibility 재검사, 가입 대기·별도 동의·가입 전 본인확인 정책, 회원/소셜 연결의 transaction 보강, 세션에 묶인 DB 일회용 교환코드, 알려진 레이아웃 구조의 UI 삽입 보강. 2FA 계정의 소셜 로그인 완료에는 위 제한 사항이 적용됩니다.
- **1.5.0:** 제공자 표시 순서와 드래그·위/아래 이동, 비활성 제공자 순서 보존, 설정 변경 후 관련 공개 레이아웃 캐시 무효화.
- **1.4.0:** GitHub 지원 추가.
- **1.3.0:** Facebook 지원 추가; Facebook 이메일의 기존 회원 자동 매칭 제외.
- **1.2.0:** Naver 지원 추가.
- **1.1.1:** MariaDB 테이블 prefix를 고려한 unique index 이름 조정.
- **1.1.0:** Glitter.kr 식별자·namespace로 이전하고 기존 테이블 rename migration 추가.

## Development

정적 소스 확인과 운영 검증은 구분합니다. 테스트는 운영 DB·cache·session 등과 구조적으로 격리된 환경에서만 실행하세요. 설치·업데이트 후에는 실제 활성 설치본과 다섯 제공자 설정, 기존 연결 회원의 로그인 경로를 확인해야 합니다.

라이선스 전문은 [LICENSE](./LICENSE)를 참고하세요.
