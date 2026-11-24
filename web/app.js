/**
 * app.js : ClipVault 화면 동작 (순수 JavaScript ES6+, Fetch API 사용 / 프레임워크 없음)
 *
 * 구성
 *   1) 공통 도구   : 요소 찾기, 알림, 복사, API 호출
 *   2) 화면 상태   : 로그인 / 비로그인 화면 전환, 하단 상태 바 갱신
 *   3) 기능        : 로그인·회원가입·로그아웃, 클립 목록/저장/삭제, PIN 발급/열람
 *   4) 시작        : 이벤트 연결, 페이지 열 때 로그인 상태 확인
 *
 * API 주소는 모두 "/api/..." 상대 경로다. 프록시(cv-proxy)가 같은 주소 하나로
 * 화면과 두 API 서버를 모두 연결해 주므로 쿠키(SESSION_ID)가 그대로 전달된다.
 */
'use strict';

/* ======================================================================
 * 1. 공통 도구
 * ==================================================================== */

/** id 로 요소를 찾는 짧은 함수 */
const $ = (id) => document.getElementById(id);

/** 화면 아래에 잠깐 떴다 사라지는 알림 */
let toastTimer = null;
function toast(message) {
  const el = $('toast');
  el.textContent = message;
  el.hidden = false;
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => { el.hidden = true; }, 2200);
}

/**
 * 클립보드에 복사한다.
 * navigator.clipboard 는 https 또는 localhost 에서만 동작한다.
 * http://<VM_IP> 로 접속하면 막히므로, 그 경우 예전 방식(execCommand)으로 대신 복사한다.
 */
async function copyText(text) {
  try {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(text);
      return true;
    }
  } catch (e) { /* 실패하면 아래 대체 방식으로 진행 */ }

  const area = document.createElement('textarea');   // 화면 밖에 임시 입력창을 만들어 선택 후 복사
  area.value = text;
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  let ok = false;
  try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
  document.body.removeChild(area);
  return ok;
}

/** 복사 후 결과를 알림으로 보여 준다 */
async function copyWithToast(text) {
  toast((await copyText(text)) ? '복사했습니다.' : '복사에 실패했습니다. 직접 선택해 복사해 주세요.');
}

/**
 * 서버 API 호출 공통 함수.
 *  - credentials: 'same-origin' : 같은 주소로 보내는 요청에 SESSION_ID 쿠키를 함께 보낸다
 *  - 응답의 worker(처리한 컨테이너 이름)를 하단 상태 바에 표시한다
 *  - 실패하면 Error 를 던지고, 로그인이 풀린 경우(401) error.status 로 알 수 있다
 */
async function api(method, path, body) {
  const options = { method, credentials: 'same-origin', headers: {} };
  if (body !== undefined) {
    options.headers['Content-Type'] = 'application/json';
    options.body = JSON.stringify(body);
  }

  let res, json;
  try {
    res = await fetch(path, options);
    json = await res.json();
  } catch (e) {
    throw new Error('서버에 연결할 수 없습니다.');
  }

  if (json.worker) setWorker(json.worker);              // 마지막 응답을 처리한 서버 표시

  if (!res.ok || json.status !== 'success') {
    // 우리 서버의 오류는 message, FastAPI 입력 검증 오류(422)는 detail 배열로 온다
    const message = json.message || (res.status === 422
      ? '입력값을 확인해 주세요. (아이디 3자 이상, 비밀번호 4자 이상)'
      : `오류가 발생했습니다. (${res.status})`);
    const error = new Error(message);
    error.status = res.status;
    throw error;
  }
  return json.data;
}

/* ======================================================================
 * 2. 화면 상태
 * ==================================================================== */

/** 하단 상태 바의 worker 칸 갱신 */
function setWorker(name) { $('st-worker').textContent = name; }

/**
 * 로그인/비로그인 화면 전환.
 * user 가 객체면 로그인 상태, null 이면 비로그인 상태로 그린다.
 */
function setLoggedIn(user) {
  const loggedIn = user !== null;

  $('app-area').hidden = !loggedIn;          // 클립 입력기/목록은 로그인했을 때만
  $('guest-hint').hidden = loggedIn;
  $('btn-open-login').hidden = loggedIn;
  $('btn-open-register').hidden = loggedIn;
  $('btn-logout').hidden = !loggedIn;
  $('who').hidden = !loggedIn;

  $('who').textContent = loggedIn ? user.username : '';
  $('st-user').textContent = loggedIn ? user.username : '비로그인';
  $('st-session').textContent = loggedIn ? '연결됨 (검증 완료)' : '없음';

  if (!loggedIn) {                           // 로그아웃하면 화면에 남은 민감 정보도 비운다
    $('clip-list').textContent = '';
    $('issued').hidden = true;
  }
}

/* ======================================================================
 * 3-1. 로그인 / 회원가입 / 로그아웃
 * ==================================================================== */

let authMode = 'login';                      // 모달이 로그인용인지 회원가입용인지

/** 모달 열기 */
function openAuth(mode) {
  authMode = mode;
  $('auth-title').textContent = mode === 'login' ? '로그인' : '회원가입';
  $('auth-password').autocomplete = mode === 'login' ? 'current-password' : 'new-password';
  $('auth-msg').hidden = true;
  $('auth-form').reset();
  $('auth-dialog').showModal();              // <dialog> 기본 기능으로 모달 표시
}

/** 모달 확인 버튼: 회원가입이면 가입 후 바로 로그인까지 이어서 진행한다 */
async function submitAuth(event) {
  event.preventDefault();                    // 폼 기본 동작(페이지 이동) 막기
  const username = $('auth-username').value.trim();
  const password = $('auth-password').value;
  const button = $('auth-submit');

  button.disabled = true;
  try {
    if (authMode === 'register') {
      await api('POST', '/api/auth/register', { username, password });
    }
    const user = await api('POST', '/api/auth/login', { username, password });
    $('auth-dialog').close();
    setLoggedIn(user);
    await loadClips();
    toast(authMode === 'register' ? '가입 후 로그인했습니다.' : '로그인했습니다.');
  } catch (e) {
    $('auth-msg').textContent = e.message;   // 모달 안에 오류 메시지 표시
    $('auth-msg').hidden = false;
  } finally {
    button.disabled = false;
  }
}

/** 로그아웃: 서버(Redis)의 세션을 지우고 화면을 비로그인 상태로 되돌린다 */
async function logout() {
  try { await api('POST', '/api/auth/logout'); } catch (e) { /* 이미 만료됐어도 화면은 정리한다 */ }
  setLoggedIn(null);
  toast('로그아웃했습니다.');
}

/* ======================================================================
 * 3-2. 내 클립 (조회 / 저장 / 삭제)
 * ==================================================================== */

/** 서버에서 내 클립 목록을 받아 화면에 그린다 */
async function loadClips() {
  try {
    const data = await api('GET', '/api/clips');       // cv-clip-api 가 Redis 세션을 검증한다
    renderClips(data.clips);
    $('st-session').textContent = '연결됨 (검증 완료)';
  } catch (e) {
    if (e.status === 401) {                            // 세션이 만료/로그아웃된 경우
      setLoggedIn(null);
      toast('세션이 만료되었습니다. 다시 로그인해 주세요.');
    } else {
      toast(e.message);
    }
  }
}

/** 클립 배열을 카드 목록으로 그린다. 내용은 textContent 로 넣어 HTML 이 실행되지 않게 한다(XSS 방지) */
function renderClips(clips) {
  const list = $('clip-list');
  list.textContent = '';
  $('clip-empty').hidden = clips.length > 0;

  for (const clip of clips) {
    const li = document.createElement('li');
    li.className = 'clip';

    const pre = document.createElement('pre');
    pre.textContent = clip.content;

    const time = document.createElement('span');
    time.className = 'clip-time';
    time.textContent = clip.created_at;

    const copyBtn = document.createElement('button');
    copyBtn.type = 'button';
    copyBtn.textContent = '복사';
    copyBtn.addEventListener('click', () => copyWithToast(clip.content));

    const delBtn = document.createElement('button');
    delBtn.type = 'button';
    delBtn.className = 'danger';
    delBtn.textContent = '삭제';
    delBtn.addEventListener('click', () => deleteClip(clip.id));

    const actions = document.createElement('div');
    actions.className = 'clip-actions';
    actions.append(copyBtn, delBtn);

    const foot = document.createElement('div');
    foot.className = 'clip-foot';
    foot.append(time, actions);

    li.append(pre, foot);
    list.appendChild(li);
  }
}

/** [내 클립보드 저장] 버튼 */
async function saveClip() {
  const content = $('clip-input').value.trim();
  if (!content) { toast('내용을 입력해 주세요.'); return; }

  try {
    await api('POST', '/api/clips', { content });
    $('clip-input').value = '';
    await loadClips();
    toast('저장했습니다.');
  } catch (e) {
    toast(e.message);
  }
}

/** 클립 삭제 */
async function deleteClip(id) {
  try {
    await api('DELETE', `/api/clips/${id}`);
    await loadClips();
  } catch (e) {
    toast(e.message);
  }
}

/* ======================================================================
 * 3-3. 1회용 PIN (발급 / 열람)
 * ==================================================================== */

/** [1회용 공유 PIN 발급] 버튼: 입력한 텍스트를 서버에 올리고 6자리 PIN 을 받는다 */
async function issuePin() {
  const content = $('clip-input').value.trim();
  if (!content) { toast('공유할 내용을 입력해 주세요.'); return; }

  try {
    const data = await api('POST', '/api/secrets', { content });
    $('issued-pin').textContent = data.pin;
    $('issued').hidden = false;
    toast('PIN을 발급했습니다. 상대에게 전달하세요.');
  } catch (e) {
    toast(e.message);
  }
}

/** [열람] 버튼: 로그인 없이 PIN 으로 1회만 조회 (조회 즉시 서버에서 원문이 파기된다) */
async function redeemPin() {
  const pin = $('pin-input').value.trim();
  if (!/^\d{6}$/.test(pin)) { toast('PIN은 숫자 6자리입니다.'); return; }

  const button = $('btn-redeem');
  button.disabled = true;                       // 중복 클릭으로 두 번 조회하지 않도록 잠시 비활성화
  try {
    const data = await api('POST', '/api/secrets/redeem', { pin });
    $('redeem-text').textContent = data.secret;
    $('redeem-result').hidden = false;
    $('pin-input').value = '';
    toast('열람했습니다. 이 내용은 다시 볼 수 없습니다.');
  } catch (e) {
    $('redeem-result').hidden = true;
    toast(e.message);                           // 없는/이미 열람된 PIN 이면 서버가 404 를 돌려준다
  } finally {
    button.disabled = false;
  }
}

/* ======================================================================
 * 4. 시작: 이벤트 연결 + 로그인 상태 확인
 * ==================================================================== */

/** 페이지를 열 때 이미 로그인된 상태(쿠키/Redis 세션이 살아 있음)인지 확인한다 */
async function init() {
  try {
    const user = await api('GET', '/api/auth/me');
    setLoggedIn(user);
    await loadClips();
  } catch (e) {
    setLoggedIn(null);                          // 401 이면 비로그인 화면
  }
}

// 버튼과 기능 연결
$('btn-open-login').addEventListener('click', () => openAuth('login'));
$('btn-open-register').addEventListener('click', () => openAuth('register'));
$('btn-logout').addEventListener('click', logout);
$('auth-form').addEventListener('submit', submitAuth);
$('auth-cancel').addEventListener('click', () => $('auth-dialog').close());

$('btn-save').addEventListener('click', saveClip);
$('btn-issue').addEventListener('click', issuePin);
$('btn-refresh').addEventListener('click', loadClips);
$('btn-copy-pin').addEventListener('click', () => copyWithToast($('issued-pin').textContent));

$('btn-redeem').addEventListener('click', redeemPin);
$('pin-input').addEventListener('keydown', (e) => { if (e.key === 'Enter') redeemPin(); });
$('btn-copy-redeem').addEventListener('click', () => copyWithToast($('redeem-text').textContent));

// 다른 기기에서 저장한 클립을 보려면 탭으로 돌아올 때 목록을 다시 불러온다 (다기기 동기화 체감용)
document.addEventListener('visibilitychange', () => {
  if (!document.hidden && !$('app-area').hidden) loadClips();
});

init();
