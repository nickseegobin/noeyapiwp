# NoeyAPI — React / Next.js Integration Guide

> **Version:** 1.0.0
> **Base namespace:** `noey/v1`
> **Full REST base:** `{WORDPRESS_URL}/wp-json/noey/v1`

---

## Table of Contents

1. [Environment Setup](#1-environment-setup)
2. [Authentication](#2-authentication)
3. [API Client Setup](#3-api-client-setup)
4. [TypeScript Types](#4-typescript-types)
5. [Endpoints — Auth](#5-endpoints--auth)
6. [Endpoints — Children](#6-endpoints--children)
7. [Endpoints — Tokens](#7-endpoints--tokens)
8. [Endpoints — Exams](#8-endpoints--exams)
9. [Endpoints — Results](#9-endpoints--results)
10. [Endpoints — Insights](#10-endpoints--insights)
11. [Error Handling](#11-error-handling)
12. [Complete Flow Example](#12-complete-flow-example)
13. [Session State Machine](#13-session-state-machine)
14. [PIN Setup Recovery Flow (React)](#14-pin-setup-recovery-flow-react)
15. [Endpoints — Leaderboard](#15-endpoints--leaderboard)

---

## 1. Environment Setup

### Next.js `.env.local`

```env
NEXT_PUBLIC_API_BASE=https://your-wordpress-site.com/wp-json/noey/v1
```

> **Never** put the JWT secret or any admin credentials in client-side env vars. All auth is token-based from the login response.

---

## 2. Authentication

### How it works

NoeyAPI uses **JWT Bearer tokens** (7-day expiry). The flow is:

```
POST /auth/login  →  receive { token }
All subsequent requests  →  Authorization: Bearer {token}
```

### Token storage

Store the JWT in `localStorage` (or a secure cookie for SSR apps):

```ts
// store
localStorage.setItem('noey_token', data.token)

// retrieve
const token = localStorage.getItem('noey_token')

// clear on logout
localStorage.removeItem('noey_token')
```

### Parent vs. Child context

A **parent** account holds the wallet and can manage children. A **child** profile is switched to using `POST /children/{id}/switch`. After switching, the JWT still belongs to the parent but the API resolves the *active child* automatically from server-side meta — the client does not need to change the token.

---

## 3. API Client Setup

### Axios instance (recommended)

```ts
// lib/api.ts
import axios from 'axios'

const api = axios.create({
  baseURL: process.env.NEXT_PUBLIC_API_BASE,
  headers: { 'Content-Type': 'application/json' },
})

// Attach JWT from storage on every request
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('noey_token')
  if (token) config.headers.Authorization = `Bearer ${token}`
  return config
})

// Global error handler
api.interceptors.response.use(
  (res) => res,
  (err) => {
    const status = err.response?.status
    if (status === 401) {
      // Token expired — redirect to login
      localStorage.removeItem('noey_token')
      window.location.href = '/login'
    }
    return Promise.reject(err)
  }
)

export default api
```

### Native fetch helper

```ts
// lib/fetchApi.ts
const BASE = process.env.NEXT_PUBLIC_API_BASE

function getToken() {
  return typeof window !== 'undefined' ? localStorage.getItem('noey_token') : null
}

export async function apiFetch<T>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const token = getToken()
  const res = await fetch(`${BASE}${path}`, {
    ...options,
    headers: {
      'Content-Type': 'application/json',
      ...(token ? { Authorization: `Bearer ${token}` } : {}),
      ...options.headers,
    },
  })
  const json = await res.json()
  if (!res.ok) throw json
  return json.data as T
}
```

---

## 4. TypeScript Types

```ts
// types/noey.ts

export interface NoeyUser {
  user_id: number
  display_name: string
  email: string
  role: 'parent' | 'child' | 'admin'
  active_child_id: number | null
  token_balance: number | null
  children?: ChildProfile[]
}

export interface ChildProfile {
  child_id: number
  display_name: string
  nickname: string | null  // Caribbean leaderboard alias — generated via admin or signup flow
  standard: string        // 'std_4' | 'std_5'
  term: string            // 'term_1' | 'term_2' | 'term_3' | ''
  age: number | null
  avatar_index: number    // 1–5
  created_at: string
}

export interface TokenBalance {
  balance: number
  tokens_lifetime: number
}

export interface LedgerEntry {
  ledger_id: number
  user_id: number
  amount: number          // positive = credit, negative = debit
  balance_after: number
  type: 'purchase' | 'exam_deduct' | 'registration' | 'monthly_refresh' | 'admin_credit' | 'admin_deduct' | 'refund'
  reference_id: string | null
  note: string | null
  created_at: string
}

export interface ExamCatalogueEntry {
  standard: string
  term: string
  subject: string
  difficulty: 'easy' | 'medium' | 'hard'
  pool_count: number
}

export interface ExamPackage {
  package_id: string
  meta: {
    standard: string
    term: string
    subject: string
    difficulty: string
    topics_covered: string[]
  }
  questions: Question[]
  // answer_sheet is stripped server-side — never sent to client
}

export interface Question {
  question_id: string
  question_text: string
  options: Record<string, string>  // { A: '...', B: '...', C: '...', D: '...' }
  meta: {
    topic: string
    subtopic: string
    cognitive_level: 'recall' | 'application' | 'analysis'
  }
  // correct_answer is only present if server key is configured AND requested
}

export interface ExamSession {
  session_id: number
  external_session_id: string
  package: ExamPackage
  balance_after: number
}

export interface SubmitAnswer {
  question_id: string
  selected_answer: string   // 'A' | 'B' | 'C' | 'D'
  correct_answer: string
  is_correct: boolean
  topic: string
  subtopic?: string
  cognitive_level: 'recall' | 'application' | 'analysis'
  time_taken_seconds?: number
}

export interface SessionResult {
  session_id: number
  subject: string
  standard: string
  term: string
  difficulty: string
  score: number
  total: number
  percentage: number
  time_taken_seconds: number
  state: 'active' | 'completed' | 'cancelled'
  started_at: string
  completed_at: string | null
}

export interface InsightResult {
  session_id: number
  insight_text: string
  model_used: string
  generated_at: string
  from_cache: boolean
}

export interface WeeklyDigest {
  child_id: number
  iso_week: string          // e.g. '2026-W12'
  insight_text: string
  generated_at: string
}

export interface LeaderboardEntry {
  rank: number
  nickname: string
  points: number
  correct_count: number
  score_pct: number
  is_current_user: boolean
}

export interface LeaderboardBoard {
  board_key: string          // e.g. 'std_4:term_1:math'
  standard: string
  term: string
  subject: string
  date: string               // YYYY-MM-DD (today's board)
  total_participants: number
  entries: LeaderboardEntry[]
  my_position: number | null // null if current child is not on this board today
  my_points: number | null
}

export interface LeaderboardUpdate {
  points_earned: number
  total_points_today: number | null
  board_key: string | null
  new_rank: number | null
  previous_rank: number | null
}

export interface MyBoards {
  child_id: number
  boards: LeaderboardBoard[]
}

export interface NoeyError {
  code: string
  message: string
  data?: { status: number; [key: string]: unknown }
}
```

---

## 5. Endpoints — Auth

### `POST /auth/register` — Register a new parent account
**Auth:** None

Creates a `noey_parent` account, grants the initial free token allowance (`3`), and returns a JWT in the same shape as `/auth/login` — the client can store it and proceed without a separate login call.

**Request body:**
```json
{
  "display_name": "Jane Smith",
  "username":     "janesmith",
  "email":        "jane@example.com",
  "password":     "secret123"
}
```

| Field | Required | Notes |
|---|---|---|
| `display_name` | ✓ | Shown in UI |
| `username` | ✓ | Must be globally unique |
| `email` | ✓ | Must be globally unique and valid |
| `password` | ✓ | |

**Response:** `201 Created`
```json
{
  "success": true,
  "data": {
    "token":           "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "expires_in":      604800,
    "user_id":         42,
    "display_name":    "Jane Smith",
    "email":           "jane@example.com",
    "role":            "parent",
    "active_child_id": null
  }
}
```

**Errors:**
- `409 noey_username_taken` — username already in use
- `409 noey_email_taken` — email already registered
- `422 noey_missing_fields` — any required field missing
- `422 noey_invalid_email` — bad email format

---

### `GET /ping` — Health check
**Auth:** None

```ts
const res = await api.get('/ping')
// { status: 'ok', version: '1.0.0', time: '2026-03-23 12:00:00' }
```

---

### `POST /auth/login` — Login → JWT
**Auth:** None

**Request body:**
```json
{
  "username": "janesmith",
  "password": "secret"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
    "expires_in": 604800,
    "user_id": 42,
    "display_name": "Jane Smith",
    "email": "jane@example.com",
    "role": "parent",
    "active_child_id": null
  }
}
```

**Usage:**
```ts
async function login(username: string, password: string) {
  const { data } = await api.post('/auth/login', { username, password })
  localStorage.setItem('noey_token', data.data.token)
  return data.data
}
```

---

### `GET /auth/me` — Current user profile
**Auth:** JWT (any role)

**Response:**
```json
{
  "success": true,
  "data": {
    "user_id": 42,
    "display_name": "Jane Smith",
    "email": "jane@example.com",
    "role": "parent",
    "active_child_id": 7,
    "token_balance": 5,
    "children": [
      {
        "child_id": 7,
        "display_name": "Alex",
        "standard": "std_4",
        "term": "term_1",
        "age": 9,
        "avatar_index": 2,
        "created_at": "2026-03-01T10:00:00Z"
      }
    ]
  }
}
```

---

### `PATCH /auth/profile` — Update parent profile
**Auth:** JWT (parent only)

Updates the parent's `display_name` and/or `avatar_index`. All fields are optional — send only what needs to change.

**Request body:**
```json
{
  "display_name": "Jane Smith",
  "avatar_index": 3
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `display_name` | string | — | Min 2 characters |
| `avatar_index` | integer | — | 1–10, clamped to range |

**Response:** `200 OK`
```json
{
  "success": true,
  "data": {
    "user_id": 42,
    "display_name": "Jane Smith",
    "avatar_index": 3
  }
}
```

**Errors:**
- `422 noey_missing_fields` — body was empty (nothing to update)
- `422 noey_invalid_display_name` — display_name shorter than 2 characters

```ts
// Parent Settings page
await api.patch('/auth/profile', {
  display_name: newName,
  avatar_index: selectedAvatar,
})
```

---

### `POST /auth/pin/set` — Set / update parent PIN
**Auth:** JWT (parent only)

**Request body:**
```json
{ "pin": "1234" }
```

**Response:**
```json
{ "success": true, "data": { "pin_set": true } }
```

> PIN must be exactly 4 digits.

---

### `POST /auth/pin/verify` — Verify parent PIN
**Auth:** JWT (any role)

**Request body:**
```json
{ "pin": "1234" }
```

**Response:**
```json
{ "success": true, "data": { "verified": true } }
```

**Error (wrong PIN):**
```json
{
  "code": "noey_pin_invalid",
  "message": "Incorrect PIN. 4 attempt(s) remaining.",
  "data": { "status": 401 }
}
```

> After 5 failed attempts the account is locked for 15 minutes.

---

### `GET /auth/pin/status` — PIN lock status
**Auth:** JWT (parent only)

**Response:**
```json
{
  "success": true,
  "data": {
    "pin_set": true,
    "is_locked": false,
    "locked_until": null,
    "seconds_remaining": 0
  }
}
```

---

## 6. Endpoints — Children

> All children endpoints require a **parent** JWT.

### `GET /children` — List children
**Auth:** JWT (parent)

**Response:**
```json
{
  "success": true,
  "data": {
    "children": [ { "child_id": 7, "display_name": "Alex", ... } ],
    "active_child_id": 7,
    "can_add_more": true
  }
}
```

---

### `POST /children` — Create child profile
**Auth:** JWT (parent)

**Request body:**
```json
{
  "display_name": "Alex",
  "username": "alex_smith",
  "password": "childpass123",
  "standard": "std_4",
  "term": "term_1",
  "age": 9,
  "avatar_index": 2
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `display_name` | string | ✓ | Shown in UI |
| `username` | string | ✓ | Must be globally unique |
| `password` | string | ✓ | Child account password |
| `standard` | string | — | `std_4` or `std_5` |
| `term` | string | — | `term_1`, `term_2`, `term_3` (std_4 only) |
| `age` | integer | — | |
| `avatar_index` | integer | — | 1–5, default 1 |

**Response:** `201 Created` with child profile object.

> Max 3 children per parent. Returns `422` if limit reached.

---

### `GET /children/{child_id}` — Get single child
**Auth:** JWT (parent, must own child)

```ts
const { data } = await api.get(`/children/${childId}`)
```

---

### `PATCH /children/{child_id}` — Update child profile
**Auth:** JWT (parent, must own child)

**Request body** (all fields optional):
```json
{
  "display_name": "Alexander",
  "standard": "std_5",
  "term": "",
  "age": 10,
  "avatar_index": 3
}
```

---

### `DELETE /children/{child_id}` — Remove child
**Auth:** JWT (parent, must own child)

Requires a `confirm: true` body parameter as a secondary guard against accidental deletion from a bad client call.

**Request body:**
```json
{ "confirm": true }
```

**Response:**
```json
{ "success": true, "data": { "removed": true } }
```

**Error (missing confirm):**
```json
{
  "code": "noey_confirmation_required",
  "message": "Pass confirm: true in the request body to permanently delete this student profile.",
  "data": { "status": 422 }
}
```

> This permanently deletes the child's WP account and all associated exam data. There is no undo.

---

### `POST /children/{child_id}/switch` — Switch active child
**Auth:** JWT (parent)

Sets the parent's active child context. All subsequent exam/results calls will be scoped to this child.

```ts
await api.post(`/children/${childId}/switch`)
```

**Response:**
```json
{
  "success": true,
  "data": {
    "active_child_id": 7,
    "child": { "child_id": 7, "display_name": "Alex", ... }
  }
}
```

---

### `POST /children/deselect` — Return to parent context
**Auth:** JWT (parent)

Clears the active child. The parent is back in their own context.

```ts
await api.post('/children/deselect')
// { "active_child_id": null }
```

---

## 7. Endpoints — Tokens

### `GET /tokens/balance` — Current balance
**Auth:** JWT (any role — resolves to parent wallet)

```ts
const { data } = await api.get('/tokens/balance')
// { balance: 5, tokens_lifetime: 12 }
```

---

### `GET /tokens/ledger` — Transaction history
**Auth:** JWT (any role — resolves to parent wallet)

**Query params:**

| Param | Type | Default | Max |
|---|---|---|---|
| `limit` | integer | `50` | `100` |
| `offset` | integer | `0` | — |

```ts
const { data } = await api.get('/tokens/ledger', { params: { limit: 20, offset: 0 } })
// { ledger: [ { ledger_id, amount, balance_after, type, note, created_at }, ... ] }
```

**Ledger types:** `purchase` · `exam_deduct` · `registration` · `monthly_refresh` · `admin_credit` · `admin_deduct` · `refund`

---

## 8. Endpoints — Exams

> Exam endpoints require an **active child to be selected** on the parent account (via `POST /children/{id}/switch`). Returns `422` if no child is selected.

### `GET /exams` — Exam catalogue
**Auth:** JWT

Returns available subject/standard/difficulty combinations based on pool inventory.

**Query params (all optional):**

| Param | Values |
|---|---|
| `standard` | `std_4`, `std_5` |
| `term` | `term_1`, `term_2`, `term_3` |
| `subject` | `Mathematics`, `English Language Arts`, `Science`, `Social Studies` |
| `difficulty` | `easy`, `medium`, `hard` |

```ts
const { data } = await api.get('/exams', {
  params: { standard: 'std_4', term: 'term_1' }
})
// { catalogue: [ { standard, term, subject, difficulty, pool_count }, ... ] }
```

---

### `POST /exams/start` — Start an exam
**Auth:** JWT (parent with active child selected)

Serves an exam package from the pool and **deducts 1 token**.

**Request body:**
```json
{
  "standard": "std_4",
  "term": "term_1",
  "subject": "Mathematics",
  "difficulty": "medium"
}
```

**Response:**
```json
{
  "success": true,
  "data": {
    "session_id": 101,
    "external_session_id": "ses_abc123",
    "balance_after": 4,
    "package": {
      "package_id": "pkg-std_4-term_1-math-medium-1234",
      "meta": {
        "standard": "std_4",
        "term": "term_1",
        "subject": "Mathematics",
        "difficulty": "medium",
        "topics_covered": ["Fractions", "Decimals"]
      },
      "questions": [
        {
          "question_id": "q-001",
          "question_text": "What is 3/4 + 1/4?",
          "options": { "A": "1", "B": "1/2", "C": "3/8", "D": "2" },
          "meta": {
            "topic": "Fractions",
            "subtopic": "Addition",
            "cognitive_level": "recall"
          }
        }
      ]
    }
  }
}
```

> **Note:** `answer_sheet` is always stripped from the response. Scoring is done server-side on submit.

**Errors:**
- `402` — Insufficient tokens
- `404` — No exam available for this selection (pool empty)

---

### `GET /exams/active` — Get the current active session
**Auth:** JWT (child context required)

Returns the child's most recent `active` session with its checkpoint attached, or `null` if no exam is in progress. Call this on app mount to detect and offer recovery of an interrupted exam.

```ts
const { data } = await api.get('/exams/active')
// data.data.session → { session_id, external_session_id, subject, standard, term,
//                        difficulty, started_at, checkpoint } | null
```

**Response (session in progress):**
```json
{
  "success": true,
  "data": {
    "session": {
      "session_id": 101,
      "external_session_id": "ses_abc123",
      "subject": "Mathematics",
      "standard": "std_4",
      "term": "term_1",
      "difficulty": "medium",
      "started_at": "2026-03-23T14:00:00Z",
      "checkpoint": {
        "session_id": 101,
        "state": { "current_question": 3, "answers": { "q-001": "A" } },
        "saved_at": "2026-03-23T14:05:00Z"
      }
    }
  }
}
```

**Response (no active session):**
```json
{ "success": true, "data": { "session": null } }
```

> This endpoint does **not** return the full question package — it only returns session metadata and checkpoint state. Use `session_id` from this response with `GET /exams/{session_id}/checkpoint` if you need to re-fetch checkpoint state only.

---

### `GET /exams/{session_id}/checkpoint` — Get saved checkpoint
**Auth:** JWT

Returns the last saved mid-exam state, or `null` if none.

```ts
const { data } = await api.get(`/exams/${sessionId}/checkpoint`)
// { checkpoint: { session_id, state: { ... }, saved_at } | null }
```

---

### `POST /exams/{session_id}/checkpoint` — Save checkpoint
**Auth:** JWT

Save mid-exam progress. Call periodically or on app background/blur.

**Request body:**
```json
{
  "state": {
    "current_question": 5,
    "answers": { "q-001": "A", "q-002": "C" },
    "elapsed_seconds": 240
  }
}
```

**Response:**
```json
{ "success": true, "data": { "saved": true } }
```

> State can be any JSON-serializable object — the API stores and returns it as-is.

---

### `DELETE /exams/{session_id}` — Cancel an active exam
**Auth:** JWT (child context required)

Marks the session as `cancelled` and clears the checkpoint. The token consumed on start is **not refunded**. Call this when the user deliberately exits mid-exam so the session is cleanly closed rather than left as `active` indefinitely.

```ts
await api.delete(`/exams/${sessionId}`)
```

**Response:**
```json
{ "success": true, "data": { "cancelled": true, "session_id": 101 } }
```

**Error:** `404` if the session is not found or is already completed/cancelled.

> Active sessions do **not** block starting a new exam — the start endpoint only checks token balance. However, explicitly cancelling abandoned sessions keeps reporting accurate.

---

### `POST /exams/{session_id}/submit` — Submit exam answers
**Auth:** JWT

Submits the completed exam. Triggers scoring and persists results.

**Request body:**
```json
{
  "answers": [
    {
      "question_id": "q-001",
      "selected_answer": "A",
      "correct_answer": "A",
      "is_correct": true,
      "topic": "Fractions",
      "subtopic": "Addition",
      "cognitive_level": "recall",
      "time_taken_seconds": 45
    }
  ]
}
```

| Field | Type | Required | Notes |
|---|---|---|---|
| `question_id` | string | ✓ | From package |
| `selected_answer` | string | ✓ | `A`–`D` |
| `correct_answer` | string | ✓ | From package (client must hold this) |
| `is_correct` | boolean | ✓ | Pre-computed client-side |
| `topic` | string | ✓ | From question meta |
| `subtopic` | string | — | |
| `cognitive_level` | string | ✓ | `recall` \| `application` \| `analysis` |
| `time_taken_seconds` | integer | — | Per-question time |

**Response:**
```json
{
  "success": true,
  "data": {
    "session_id": 101,
    "score": 8,
    "total": 10,
    "percentage": 80.0,
    "time_taken_seconds": 420,
    "topic_breakdown": [
      { "topic": "Fractions", "correct": 4, "total": 5, "percentage": 80.0 }
    ],
    "leaderboard_update": {
      "points_earned": 9,
      "total_points_today": 17,
      "board_key": "std_4:term_1:math",
      "new_rank": 3,
      "previous_rank": 5
    }
  }
}
```

> `leaderboard_update` is `null` if the leaderboard upsert failed (e.g. Railway unreachable). Exam results are never affected by a leaderboard failure.
>
> **Points formula:** `points = correct_count + difficulty_bonus` where bonus is `0` (easy), `1` (medium), or `2` (hard).

---

## 9. Endpoints — Results

### `GET /results` — Exam history (paginated)
**Auth:** JWT

**Query params:**

| Param | Type | Default | Max | Notes |
|---|---|---|---|---|
| `page` | integer | `1` | — | |
| `per_page` | integer | `20` | `100` | |
| `child_id` | integer | — | — | **Parent only.** Read a specific child's history without switching active context. Useful for analytics overview screens. |

```ts
// Active child's history (normal usage)
const { data } = await api.get('/results', { params: { page: 1, per_page: 20 } })

// Parent reading a specific child's history without switching context
const { data } = await api.get('/results', { params: { child_id: 7, per_page: 5 } })
```

**Response:**
```json
{
  "success": true,
  "data": {
    "sessions": [ { "session_id": 101, "subject": "Mathematics", "score": 8, "total": 10, "percentage": 80, ... } ],
    "total": 42,
    "page": 1,
    "per_page": 20
  }
}
```

---

### `GET /results/stats` — Aggregate stats
**Auth:** JWT

Returns overall performance stats and topic breakdown for the active child. Pass `?child_id=X` as a parent to fetch any owned child's stats without changing the active-child context — this is the intended pattern for Analytics Overview screens that need to render summary cards for all children simultaneously.

**Query params:**

| Param | Type | Notes |
|---|---|---|
| `child_id` | integer | **Parent only.** Bypasses active-child context. Returns `403` if the parent doesn't own the child. |

```ts
// Active child's stats (normal usage)
const { data } = await api.get('/results/stats')

// Analytics Overview — fetch all children in parallel without switching context
const children = [7, 8, 9]
const stats = await Promise.all(
  children.map(id => api.get('/results/stats', { params: { child_id: id } }))
)
```

**Response:**
```json
{
  "success": true,
  "data": {
    "total_exams": 12,
    "average_score": 74.5,
    "best_subject": "Science",
    "weakest_topic": "Long Division",
    "topic_breakdown": [
      { "topic": "Fractions", "correct": 20, "total": 25, "percentage": 80.0 }
    ]
  }
}
```

---

### `GET /results/{session_id}` — Session detail + answers
**Auth:** JWT

```ts
const { data } = await api.get(`/results/${sessionId}`)
```

**Response:**
```json
{
  "success": true,
  "data": {
    "session": { "session_id": 101, "subject": "Mathematics", "percentage": 80, ... },
    "answers": [
      {
        "question_id": "q-001",
        "topic": "Fractions",
        "selected_answer": "A",
        "correct_answer": "A",
        "is_correct": true,
        "time_taken_seconds": 45
      }
    ],
    "topic_breakdown": [ ... ]
  }
}
```

---

## 10. Endpoints — Insights

### `POST /insights/exam/{session_id}` — Generate per-exam insight
**Auth:** JWT

Generates an AI coaching note for a completed session. **Cached** — calling again returns the stored result instantly.

```ts
const { data } = await api.post(`/insights/exam/${sessionId}`)
```

**Response:**
```json
{
  "success": true,
  "data": {
    "session_id": 101,
    "insight_text": "Great work on Fractions! You showed strong recall skills...",
    "model_used": "claude-sonnet-4-6",
    "generated_at": "2026-03-23T16:30:00Z",
    "from_cache": false
  }
}
```

---

### `GET /insights/exam/{session_id}` — Retrieve stored insight
**Auth:** JWT

Returns a previously generated insight without triggering a new one. Returns `404` if none exists yet.

```ts
const { data } = await api.get(`/insights/exam/${sessionId}`)
```

---

### `GET /insights/weekly/{iso_week}` — Weekly digest
**Auth:** JWT

Retrieves the AI-generated weekly summary for the active child.

**ISO week format:** `YYYY-Www` (e.g. `2026-W12`)

```ts
// Get current week's digest
const week = getCurrentIsoWeek() // '2026-W12'
const { data } = await api.get(`/insights/weekly/${week}`)
```

**Response:**
```json
{
  "success": true,
  "data": {
    "child_id": 7,
    "iso_week": "2026-W12",
    "insight_text": "Alex had a productive week, completing 3 exams...",
    "generated_at": "2026-03-24T06:00:00Z"
  }
}
```

**ISO week helper:**
```ts
function getCurrentIsoWeek(): string {
  const now = new Date()
  const jan1 = new Date(now.getFullYear(), 0, 1)
  const week = Math.ceil(
    ((now.getTime() - jan1.getTime()) / 86400000 + jan1.getDay() + 1) / 7
  )
  return `${now.getFullYear()}-W${String(week).padStart(2, '0')}`
}
```

---

### `POST /insights/weekly/{iso_week}` — Trigger weekly digest
**Auth:** JWT

Manually generates (or regenerates) the weekly digest for the active child.

```ts
await api.post(`/insights/weekly/${week}`)
```

---

## 11. Error Handling

All errors follow this shape:

```json
{
  "code": "noey_insufficient_tokens",
  "message": "No tokens available. Please purchase more to continue.",
  "data": {
    "status": 402,
    "balance": 0
  }
}
```

### Common error codes

| Code | HTTP | Meaning |
|---|---|---|
| `noey_invalid_credentials` | 401 | Wrong username or password |
| `noey_token_invalid` | 401 | JWT missing, expired, or malformed |
| `noey_forbidden` | 403 | Wrong role for this endpoint |
| `noey_not_found` | 404 | Resource not found |
| `noey_insufficient_tokens` | 402 | Balance is 0 — prompt to purchase |
| `noey_no_exam_available` | 404 | Pool empty for this selection |
| `noey_no_active_child` | 422 | Parent must switch to a child first |
| `noey_max_children` | 422 | Limit of 3 children reached |
| `noey_username_taken` | 409 | Username already in use |
| `noey_pin_invalid` | 401 | Wrong PIN |
| `noey_pin_locked` | 429 | Too many PIN attempts — locked 15 min |
| `noey_pin_not_set` | 422 | Parent has not set a PIN yet |
| `noey_session_not_found` | 404 | Exam session not found or already submitted |
| `noey_email_taken` | 409 | Email already registered (registration) |
| `noey_confirmation_required` | 422 | Must pass `confirm: true` to delete a child profile |
| `noey_invalid_display_name` | 422 | display_name too short (min 2 chars) |

### React error handler

```ts
import { AxiosError } from 'axios'

interface NoeyError {
  code: string
  message: string
  data?: { status: number }
}

export function getApiError(err: unknown): NoeyError {
  if (err instanceof AxiosError && err.response?.data) {
    return err.response.data as NoeyError
  }
  return { code: 'unknown', message: 'An unexpected error occurred.' }
}

// Usage
try {
  await api.post('/exams/start', payload)
} catch (err) {
  const { code, message } = getApiError(err)
  if (code === 'noey_insufficient_tokens') {
    router.push('/purchase-tokens')
  } else {
    toast.error(message)
  }
}
```

---

## 12. Complete Flow Example

End-to-end flow for a parent logging in, selecting a child, and completing an exam:

```ts
import api from '@/lib/api'

// 1. Login
const { data: auth } = await api.post('/auth/login', { username, password })
localStorage.setItem('noey_token', auth.data.token)

// 2. Load profile (includes children list and token balance)
const { data: profile } = await api.get('/auth/me')
// profile.data.token_balance  → 5
// profile.data.children       → [{ child_id: 7, display_name: 'Alex', ... }]

// 3. Switch to child
await api.post(`/children/${profile.data.children[0].child_id}/switch`)

// 4. Browse catalogue
const { data: catalogue } = await api.get('/exams', {
  params: { standard: 'std_4', term: 'term_1' }
})

// 5. Start exam (deducts 1 token)
const { data: session } = await api.post('/exams/start', {
  standard: 'std_4',
  term: 'term_1',
  subject: 'Mathematics',
  difficulty: 'medium',
})
const sessionId = session.data.session_id
const questions = session.data.package.questions

// 6. Save a checkpoint mid-exam
await api.post(`/exams/${sessionId}/checkpoint`, {
  state: { current_question: 3, answers: { 'q-001': 'A' } }
})

// 7. Submit answers
const { data: result } = await api.post(`/exams/${sessionId}/submit`, {
  answers: questions.map((q, i) => ({
    question_id:        q.question_id,
    selected_answer:    userAnswers[i],
    correct_answer:     q.correct_answer,   // stored client-side from package
    is_correct:         userAnswers[i] === q.correct_answer,
    topic:              q.meta.topic,
    subtopic:           q.meta.subtopic,
    cognitive_level:    q.meta.cognitive_level,
    time_taken_seconds: timings[i],
  }))
})
// result.data → { session_id, score, total, percentage, topic_breakdown }

// 8. Generate AI insight
const { data: insight } = await api.post(`/insights/exam/${sessionId}`)
// insight.data.insight_text → "Great work Alex! You scored..."

// 9. View history
const { data: history } = await api.get('/results', { params: { per_page: 10 } })

// 10. Get stats
const { data: stats } = await api.get('/results/stats')
```

---

## WooCommerce Token Purchases

When a parent purchases a WooCommerce product that has **"Noey Tokens granted"** set:
- The order must reach **Completed** status
- Tokens are automatically credited to the parent's wallet
- The ledger entry has `type: "purchase"` and `reference_id: "{order_id}"`
- Balance updates are reflected immediately on the next `GET /tokens/balance` call

No special API call is needed — purchases feed directly into the wallet.

---

## Cron Jobs

These run automatically and require no client-side action:

| Job | Schedule | What it does |
|---|---|---|
| Monthly token refresh | 1st of each month, 00:05 UTC | Resets all **free-tier** accounts to `3` tokens |
| Weekly digest | Every Monday, 06:00 UTC | Generates AI weekly insight for any child who completed ≥1 exam in the past 7 days |
| Leaderboard daily reset | Daily, 04:00 UTC (Trinidad midnight) | Clears all board point totals in Railway; rankings start fresh each day |

---

## 13. Session State Machine

Every exam session has a `state` field that follows this lifecycle:

```
          POST /exams/start
                │
                ▼
           ┌─────────┐
           │  active  │
           └─────────┘
            │         │
            │         │
  POST      │         │  DELETE
  /exams/   │         │  /exams/{session_id}
  {id}/     │         │  (cancel — no refund)
  submit    │         │
            ▼         ▼
       ┌──────────┐  ┌───────────┐
       │ completed│  │ cancelled │
       └──────────┘  └───────────┘
```

### States

| State | Description |
|---|---|
| `active` | Exam is in progress. Checkpoint saves are allowed. Submit and cancel are allowed. |
| `completed` | Answers submitted, score calculated. Read-only via `/results/{session_id}`. |
| `cancelled` | Exam was abandoned via `DELETE /exams/{session_id}`. Token is **not** refunded. |

### Key behaviours

- **A new exam can always be started** — having an `active` session does not block `POST /exams/start`. If the parent starts a new exam without cancelling the previous one, the old session remains `active` in the database but will never be submitted. Cancel it explicitly to keep records clean.
- **Checkpoints are optional** — `POST /exams/{id}/checkpoint` saves the client's in-progress state to the server. On app reload, call `GET /exams/active` to restore. If no checkpoint exists the response returns `null` for `checkpoint`.
- **Cancel does not refund** — the token is consumed when `POST /exams/start` succeeds. Cancellation is a no-cost administrative action, not a refund path.

### Recommended React flow

```ts
// On app mount / exam screen entry — check for an interrupted session
const { data } = await api.get('/exams/active')
const active = data.data.session  // null if no exam in progress

if (active) {
  // Offer to resume or discard
  const resume = confirm('You have an unfinished exam. Resume?')
  if (!resume) {
    await api.delete(`/exams/${active.session_id}`)
    // now start a fresh exam
  } else {
    // restore from active.checkpoint (may be null if no checkpoint saved yet)
  }
}
```

---

## 14. PIN Setup Recovery Flow (React)

### Background

Parents can protect profile switching with a 4-digit PIN (`POST /auth/pin/set`). This PIN is **optional** — new accounts do not have one. If a parent has not set a PIN and something calls `POST /children/{id}/switch` or `POST /auth/pin/verify`, the API returns:

```json
{
  "code": "noey_pin_not_set",
  "message": "No PIN has been set. Please create a PIN first.",
  "data": { "status": 422 }
}
```

### React handler

Intercept this code at the **profile selector** / **switch screen** and redirect to PIN setup instead of showing a generic error:

```ts
import { useRouter } from 'next/navigation'
import { getApiError } from '@/lib/api'

export function ProfileSelector() {
  const router = useRouter()

  async function handleSwitch(childId: number) {
    try {
      await api.post(`/children/${childId}/switch`)
      router.push('/child/dashboard')
    } catch (err) {
      const { code, message } = getApiError(err)

      if (code === 'noey_pin_not_set') {
        // First-time: send parent to create their PIN
        router.push('/settings/pin/create')
      } else if (code === 'noey_pin_invalid') {
        toast.error('Incorrect PIN. Please try again.')
      } else if (code === 'noey_pin_locked') {
        toast.error('PIN locked for 15 minutes. Too many incorrect attempts.')
      } else {
        toast.error(message)
      }
    }
  }

  // ...
}
```

### PIN setup page (`/settings/pin/create`)

```ts
// POST /auth/pin/set
await api.post('/auth/pin/set', { pin: '1234' })
// → 200 { success: true, data: { pin_set: true } }

// After success, redirect back to the profile selector
router.push('/profile-select')
```

### PIN change / reset

```ts
// Change PIN (requires current PIN)
await api.post('/auth/pin/change', { current_pin: '1234', new_pin: '5678' })

// Admin reset (e.g. from a "Forgot PIN?" support flow)
// No client-side API — admin uses the WordPress Members tool to reset
```

### Summary of PIN-related error codes

| Code | HTTP | When |
|---|---|---|
| `noey_pin_not_set` | 422 | Parent has never created a PIN — redirect to setup |
| `noey_pin_invalid` | 401 | Wrong PIN entered |
| `noey_pin_locked` | 429 | 5 failed attempts — locked for 15 minutes |

---

## 15. Endpoints — Leaderboard

> Both leaderboard endpoints require a **JWT with an active child context** (parent must have switched to a child via `POST /children/{id}/switch`).
>
> Boards are **daily** — they reset at 04:00 UTC (Trinidad midnight). Board keys are scoped to `standard + term + subject`; difficulty is not part of the key (points from all difficulties accumulate into the same board).

### `GET /leaderboard/{standard}/{term}/{subject}` — Subject board
**Auth:** JWT (child context required)

Returns today's top 10 for the given subject board. The current child is flagged with `is_current_user: true` and their position is included even if they are outside the top 10.

| Segment | Format | Example | Notes |
|---|---|---|---|
| `standard` | slug | `std_4` | |
| `term` | slug | `term_1` | Pass `none` for std_5 boards |
| `subject` | slug | `math` | Use Railway slugs, not display names |

**Subject slugs:**

| Display name | Slug |
|---|---|
| Mathematics | `math` |
| English Language Arts | `english` |
| Science | `science` |
| Social Studies | `social_studies` |

> **Important:** The API normalises subjects internally (a session stored as "Mathematics" is always sent to Railway as "math"). However, always send the slug when calling this endpoint directly.

```ts
const { data } = await api.get('/leaderboard/std_4/term_1/math')
```

**Response:**
```json
{
  "success": true,
  "data": {
    "board_key": "std_4:term_1:math",
    "standard": "std_4",
    "term": "term_1",
    "subject": "math",
    "date": "2026-04-02",
    "total_participants": 24,
    "entries": [
      {
        "rank": 1,
        "nickname": "TurboTortoise",
        "points": 22,
        "correct_count": 20,
        "score_pct": 95,
        "is_current_user": false
      },
      {
        "rank": 3,
        "nickname": "SunriseShark",
        "points": 17,
        "correct_count": 16,
        "score_pct": 80,
        "is_current_user": true
      }
    ],
    "my_position": 3,
    "my_points": 17
  }
}
```

> `my_position` and `my_points` are `null` if the current child has not appeared on this board today.

---

### `GET /leaderboard/me` — Personal boards summary
**Auth:** JWT (child context required)

Returns all subject boards the current child appears on today, scoped to their enrolled standard and term.

```ts
const { data } = await api.get('/leaderboard/me')
```

**Response:**
```json
{
  "success": true,
  "data": {
    "child_id": 7,
    "boards": [
      {
        "board_key": "std_4:term_1:math",
        "standard": "std_4",
        "term": "term_1",
        "subject": "math",
        "date": "2026-04-02",
        "total_participants": 24,
        "entries": [ ... ],
        "my_position": 3,
        "my_points": 17
      }
    ]
  }
}
```

> Returns an empty `boards` array if the child has not completed any exams today.

---

### Leaderboard points formula

Points are calculated **server-side** on exam submit and sent to Railway via the upsert call. Points **accumulate throughout the day** — each exam adds to the running total.

```
points_for_exam = correct_count + difficulty_bonus

difficulty_bonus:
  easy   → 0
  medium → 1
  hard   → 2
```

**Example:** A child scores 8/10 on a hard exam → `8 + 2 = 10 points` added to their daily total.

---

### Nicknames

Every child has a **Caribbean-themed nickname** used on the leaderboard instead of their real display name. Nicknames are generated via the admin panel or during the signup flow.

- Stored in WordPress user meta as `noey_nickname`
- Sent to Railway with every upsert
- Visible in board `entries[].nickname`
- Admins can regenerate nicknames (e.g. for inappropriate content) from **WP Admin → NoeyAI → Leaderboards → Nickname Management**

---

*Generated for NoeyAPI v1.0.0 — updated 2026-04-02*
