function qs(id) { return document.getElementById(id); }

const API = {
  clans: "api/search/clans.php",
  players: "api/search/players.php",
  clanOverview: "api/clan.php",
  player: "api/player.php",
  refreshPlayerXp: "api/refresh_member_data.php",
};

const TRACKER_CONFIG = window.TRACKER_CONFIG || {};

const CLAN_RANK_ORDER_ASC = [
  "Guest",
  "Recruit",
  "Corporal",
  "Sergeant",
  "Lieutenant",
  "Captain",
  "General",
  "Admin",
  "Organiser",
  "Coordinator",
  "Overseer",
  "Deputy Owner",
  "Owner",
];

function normaliseRankForSort(rank) {
  return String(rank || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, " ")
    .replace(/\s+/g, " ")
    .trim();
}

const CLAN_RANK_ORDER_INDEX = new Map(
  CLAN_RANK_ORDER_ASC.map((rank, index) => [normaliseRankForSort(rank), index])
);

function compareRanksDescending(a, b) {
  const ai = CLAN_RANK_ORDER_INDEX.get(normaliseRankForSort(a));
  const bi = CLAN_RANK_ORDER_INDEX.get(normaliseRankForSort(b));
  const av = Number.isInteger(ai) ? ai : -1;
  const bv = Number.isInteger(bi) ? bi : -1;

  if (av !== bv) return bv - av;
  return String(a).localeCompare(String(b), undefined, { sensitivity: "base" });
}

function compareClanMembersDescending(a, b) {
  const ar = a?.rank_name ?? a?.rank ?? "";
  const br = b?.rank_name ?? b?.rank ?? "";
  const rankCompare = compareRanksDescending(ar, br);
  if (rankCompare !== 0) return rankCompare;

  return String(a?.rsn || "").localeCompare(String(b?.rsn || ""), undefined, { sensitivity: "base" });
}

function clanRankAssetPath(rankName) {
  const key = String(rankName || "")
    .trim()
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, "");

  const fileMap = {
    owner: "Owner",
    deputyowner: "DeputyOwner",
    overseer: "Overseer",
    coordinator: "Coordinator",
    organiser: "Organiser",
    organizer: "Organiser",
    admin: "Admin",
    general: "General",
    captain: "Captain",
    lieutenant: "Lieutenant",
    sergeant: "Sergeant",
    corporal: "Corporal",
    recruit: "Recruit",
  };

  const fileBase = fileMap[key];
  return fileBase ? `assets/ranks/${fileBase}_Clan_Rank.png` : "";
}

function clanRankLineHtml(rankName, className = "rankLine") {
  const rank = String(rankName || "").trim();
  if (!rank) return `<div class="${escapeHtml(className)}"><span class="rankName">—</span></div>`;

  const icon = clanRankAssetPath(rank);
  const iconHtml = icon
    ? `<img class="rankIcon" src="${escapeHtml(icon)}" alt="" loading="lazy" onerror="this.remove()" />`
    : "";

  return `<div class="${escapeHtml(className)}">${iconHtml}<span class="rankName">${escapeHtml(rank)}</span></div>`;
}

function trackerBrandLogoPath() {
  return String(TRACKER_CONFIG.brandLogoUrl || "assets/hit-media.png").trim() || "assets/hit-media.png";
}

function playerRankPillHtml(rankName, clanName = "Clan") {
  const rank = String(rankName || "").trim() || "—";
  const rankIcon = clanRankAssetPath(rank);
  const rankIconHtml = rankIcon
    ? `<img class="rankIcon" src="${escapeHtml(rankIcon)}" alt="" loading="lazy" onerror="this.remove()" />`
    : "";
  const logo = trackerBrandLogoPath();
  const logoHtml = logo
    ? `<span class="rankDivider" aria-hidden="true"></span><img class="rankClanLogo" src="${escapeHtml(logo)}" alt="${escapeHtml(clanName)} logo" loading="lazy" onerror="this.remove()" />`
    : "";

  return `<div class="playerRankLine">${rankIconHtml}<span class="rankName">${escapeHtml(rank)}</span>${logoHtml}</div>`;
}

function memberTitleRankHtml(rsn, rankName) {
  const rank = String(rankName || "").trim();
  const icon = clanRankAssetPath(rank);
  const iconHtml = icon
    ? `<img class="memberRankIconLarge" src="${escapeHtml(icon)}" alt="" loading="lazy" onerror="this.remove()" />`
    : "";

  return `
    <div class="memberTitleRow">
      ${iconHtml}
      <div class="memberTitleText">
        <div class="memberName">${escapeHtml(rsn || "—")}</div>
        ${rank ? `<div class="memberRankName">${escapeHtml(rank)}</div>` : ""}
      </div>
    </div>
  `;
}

function getConfiguredClanId() {
  return String(TRACKER_CONFIG.clanId || "").trim();
}

function isIndexViewAvailable() {
  return !!qs("viewClan") && !!qs("viewPlayer");
}

function appIndexUrl(params = {}) {
  const url = new URL("index", window.location.href);
  Object.entries(params).forEach(([key, value]) => {
    const v = String(value || "").trim();
    if (v) url.searchParams.set(key, v);
  });
  return url.toString();
}

function openPlayerProfile(rsn) {
  const value = normalise(rsn);
  if (!value) return;

  if (isIndexViewAvailable()) setQuery({ player: value });
  else window.location.href = appIndexUrl({ player: value });
}

/* ---------------- XP refresh helper ---------------- */

function parseUtcToMs(utc) {
  const s = String(utc || "").trim();
  if (!s) return null;
  // expects "YYYY-MM-DD HH:mm:ss" (UTC)
  const iso = s.includes("T") ? s : s.replace(" ", "T");
  const d = new Date(`${iso}Z`);
  const ms = d.getTime();
  return Number.isFinite(ms) ? ms : null;
}

function utcAgeSeconds(utc) {
  const ms = parseUtcToMs(utc);
  if (ms === null) return null;
  return Math.floor((Date.now() - ms) / 1000);
}

const xpRefreshAttempted = new Set();

function getParams() {
  const p = new URLSearchParams(window.location.search);
  return {
    clan: (p.get("clan") || "").trim(),
    player: (p.get("player") || "").trim(),
    configuredClan: getConfiguredClanId(),
  };
}

function setQuery(params) {
  const url = new URL(window.location.href);
  url.searchParams.delete("clan");
  url.searchParams.delete("player");
  for (const [k, v] of Object.entries(params)) {
    if (v && String(v).trim()) url.searchParams.set(k, String(v).trim());
  }
  window.history.pushState({}, "", url);
  render();
}

function clearQuery() {
  const url = new URL(window.location.href);
  url.searchParams.delete("clan");
  url.searchParams.delete("player");
  window.history.pushState({}, "", url);
  render();
}

function show(el, yes) { if (el) el.classList.toggle("hidden", !yes); }
function normalise(v) { return String(v || "").trim(); }

function debounce(fn, delay = 250) {
  let t;
  return (...args) => {
    clearTimeout(t);
    t = setTimeout(() => fn(...args), delay);
  };
}

async function fetchJson(url) {
  const res = await fetch(url, { cache: "no-store" });
  const text = await res.text();
  try {
    return JSON.parse(text);
  } catch {
    return { ok: false, error: "Invalid JSON response", hint: text.slice(0, 200) };
  }
}

function escapeHtml(s) {
  return String(s)
    .replaceAll("&", "&amp;")
    .replaceAll("<", "&lt;")
    .replaceAll(">", "&gt;")
    .replaceAll('"', "&quot;")
    .replaceAll("'", "&#039;");
}

function formatNumber(n) {
  if (n === null || n === undefined) return "—";
  const x = Number(n);
  if (!Number.isFinite(x)) return "—";
  return x.toLocaleString("en-AU");
}

function formatNumbersInText(input) {
  const s = String(input || "");
  return s.replace(/(\d{1,3}(?:,\d{3})+|\d{4,})/g, (m) => {
    const raw = m.replace(/,/g, "");
    const n = Number(raw);
    if (!Number.isFinite(n)) return m;
    return n.toLocaleString("en-AU");
  });
}

function setText(id, value) {
  const el = qs(id);
  if (el) el.textContent = value;
}

function parseUtcDateToMs(value) {
  const s = String(value || "").trim();
  if (!s) return null;
  const iso = s.includes("T") ? s : s.replace(" ", "T");
  const hasTimezone = /(?:Z|[+-]\d{2}:?\d{2})$/i.test(iso);
  const d = new Date(hasTimezone ? iso : `${iso}Z`);
  const ms = d.getTime();
  return Number.isFinite(ms) ? ms : null;
}

function formatCountdown(msRemaining) {
  if (!Number.isFinite(msRemaining)) return "—";
  if (msRemaining <= 0) return "Reset due";

  const totalSeconds = Math.floor(msRemaining / 1000);
  const days = Math.floor(totalSeconds / 86400);
  const hours = Math.floor((totalSeconds % 86400) / 3600);
  const minutes = Math.floor((totalSeconds % 3600) / 60);
  const seconds = totalSeconds % 60;

  if (days > 0) return `${days}d ${hours}h ${minutes}m`;
  if (hours > 0) return `${hours}h ${minutes}m ${seconds}s`;
  return `${minutes}m ${seconds}s`;
}

function formatLocalResetTime(targetMs) {
  if (!Number.isFinite(targetMs)) return "Local reset: —";

  const date = new Date(targetMs);
  const localTimezone = Intl.DateTimeFormat().resolvedOptions().timeZone || "local time";

  try {
    const formatted = new Intl.DateTimeFormat("en-AU", {
      weekday: "short",
      day: "numeric",
      month: "short",
      year: "numeric",
      hour: "numeric",
      minute: "2-digit",
      timeZoneName: "short",
    }).format(date);

    return `Local reset: ${formatted} (${localTimezone})`;
  } catch {
    return `Local reset: ${date.toLocaleString()}`;
  }
}

let clanResetCountdownTimer = null;
function clearClanResetCountdown() {
  if (clanResetCountdownTimer !== null) {
    clearInterval(clanResetCountdownTimer);
    clanResetCountdownTimer = null;
  }
  setText("resetCountdownValue", "—");
  setText("resetCountdownLocalTime", "Local reset: —");
}

function setClanResetCountdown(week) {
  clearClanResetCountdown();
  const targetMs = parseUtcDateToMs(week?.week_end_utc);
  const valueEl = qs("resetCountdownValue");
  if (!valueEl || targetMs === null) return;

  setText("resetCountdownLocalTime", formatLocalResetTime(targetMs));

  const tick = () => {
    valueEl.textContent = formatCountdown(targetMs - Date.now());
  };
  tick();
  clanResetCountdownTimer = setInterval(tick, 1000);
}


/* ---------------- Icon handling (case-tolerant) ---------------- */

function titleCaseWord(s) {
  if (!s) return s;
  return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
}

function toFileKey(s) {
  return String(s || "")
    .trim()
    .toLowerCase()
    .replace(/['"]/g, "")
    .replace(/[^a-z0-9]+/g, "_")
    .replace(/^_+|_+$/g, "");
}

function iconCandidates(basePath, keyOrName) {
  const raw = String(keyOrName || "").trim();
  if (!raw) return [];

  const lower = raw.toLowerCase();
  const tc = titleCaseWord(raw);
  const noSpaces = raw.replace(/\s+/g, "");
  const lowerNoSpaces = lower.replace(/\s+/g, "");
  const fileKey = toFileKey(raw);
  const fileKeyNoUnderscore = fileKey.replace(/_/g, "");
  const baseNames = [raw, lower, tc, noSpaces, lowerNoSpaces, fileKey, fileKeyNoUnderscore];
  const extensions = ["png", "webp", "jpg", "jpeg", "svg"];

  const uniq = new Set();
  for (const baseName of baseNames) {
    if (!baseName) continue;
    for (const ext of extensions) {
      uniq.add(`${basePath}${baseName}.${ext}`);
    }
  }

  return Array.from(uniq).filter(p => !/\/\.(png|webp|jpe?g|svg)$/i.test(p));
}


/* ---------------- Activity icon map (optional, JSON-driven) ----------------
   Create either of these files:
   - assets/activity/icon_map.json
   - assets/activity_icons/icon_map.json

   Example:
   {
     "Fealty3": {"icon":"assets/activity_icons/fealty_3.png","activity_text":"Maintained Clan Fealty 3"},
     "ArchaeologyXP": {"icon":"assets/skills/archaeology.png","activity_regex":"\\bxp\\b.*\\barchaeology\\b","regex_flags":"i"}
   }

   Notes:
   - activity_text is a case-insensitive substring match against (text + details)
   - activity_regex is optional; treated as a JS RegExp string
   - icon paths may be absolute (/assets/...) or relative (assets/...)
-------------------------------------------------------------------------- */

const ACTIVITY_ICON_MAP_URLS = [
  "assets/activity/icon_map.json",
  "assets/activity_icons/icon_map.json",
];

let _activityIconMapPromise = null;

function _stripBom(s) {
  if (!s) return s;
  // Remove UTF-8 BOM if present
  return s.charCodeAt(0) === 0xFEFF ? s.slice(1) : s;
}

// Best-effort: allow comments and trailing commas in JSON-style config files.
// This keeps icon maps forgiving while still parsing them as JSON in the browser.
function _stripJsonCommentsOutsideStrings(text) {
  const input = String(text || "");
  let out = "";
  let inString = false;
  let quote = "";
  let escaped = false;

  for (let i = 0; i < input.length; i++) {
    const ch = input[i];
    const next = input[i + 1];

    if (inString) {
      out += ch;
      if (escaped) {
        escaped = false;
      } else if (ch === "\\") {
        escaped = true;
      } else if (ch === quote) {
        inString = false;
        quote = "";
      }
      continue;
    }

    if (ch === '"' || ch === "'") {
      inString = true;
      quote = ch;
      out += ch;
      continue;
    }

    if (ch === "/" && next === "/") {
      while (i < input.length && input[i] !== "\n") i++;
      out += "\n";
      continue;
    }

    if (ch === "/" && next === "*") {
      i += 2;
      while (i < input.length && !(input[i] === "*" && input[i + 1] === "/")) i++;
      i++;
      continue;
    }

    out += ch;
  }

  return out;
}

function _relaxJson(text) {
  let t = _stripBom(String(text || ""));
  t = _stripJsonCommentsOutsideStrings(t);
  // Remove trailing commas before } or ]
  t = t.replace(/,\s*(\}|\])/g, "$1");
  return t;
}

function loadActivityIconMap() {
  if (_activityIconMapPromise) return _activityIconMapPromise;

  _activityIconMapPromise = (async () => {
    for (const url of ACTIVITY_ICON_MAP_URLS) {
      try {
        const r = await fetch(url, { cache: "no-store" });
        if (!r.ok) continue;
        const txt = await r.text();
        try {
          const obj = JSON.parse(_relaxJson(txt));
          if (obj && typeof obj === "object") return obj;
        } catch {
          // invalid json, try next path
        }
      } catch {
        // ignore and try next path
      }
    }
    return {};
  })();

  return _activityIconMapPromise;
}

function _norm(s) {
  return String(s || "").toLowerCase().replace(/\s+/g, " ").trim();
}

function _mappedIconCandidates(iconPath) {
  if (!iconPath) return [];
  const raw = String(iconPath);
  const noLead = raw.replace(/^\//, "");
  const baseDir = window.location.pathname.replace(/[^\/]*$/, "");
  const basePrefixed = baseDir + noLead;

  const out = [];
  if (raw) out.push(raw);
  if (noLead && noLead !== raw) out.push(noLead);
  if (basePrefixed && basePrefixed !== raw && basePrefixed !== noLead) out.push(basePrefixed);
  return out;
}

function findMappedActivityIcon(activity, iconMap) {
  if (!iconMap) return null;
  const combined = _norm((activity?.text || "") + " " + (activity?.details || ""));

  for (const key of Object.keys(iconMap)) {
    const entry = iconMap[key];
    if (!entry || !entry.icon) continue;

    if (entry.activity_text) {
      const needle = _norm(entry.activity_text);
      if (needle && combined.includes(needle)) return entry.icon;
    }

    if (entry.activity_regex) {
      try {
        const re = new RegExp(entry.activity_regex, entry.regex_flags || "i");
        const t = (activity?.text || "");
        const d = (activity?.details || "");
        const td = `${t} ${d}`.trim();

        // Test text + details together, but also separately so anchored regexes (^...$)
        // still work even when details exists.
        if (re.test(t) || re.test(d) || re.test(td)) return entry.icon;
      } catch {
        // ignore bad regex
      }
    }
  }

  return null;
}

// Start fetching early (non-blocking)
loadActivityIconMap();

/* ---------------- Boss kill icon map ----------------
   Boss kill activities are expected in one of these formats:
   - I killed 12 Kalphite Kings.
   - I killed Kalphite King

   Add mappings in:
   - assets/activity/monsters/icon_map.json

   Example:
   {
     "Kalphite King": "kalphite_king.png"
   }
-------------------------------------------------------------------------- */

const BOSS_ICON_MAP_URLS = [
  "assets/activity/monsters/icon_map.json",
  "assets/activity/monsters/boss_icon_map.json",
];

let _bossIconMapPromise = null;

function loadBossIconMap() {
  if (_bossIconMapPromise) return _bossIconMapPromise;

  _bossIconMapPromise = (async () => {
    for (const url of BOSS_ICON_MAP_URLS) {
      try {
        const r = await fetch(url, { cache: "no-store" });
        if (!r.ok) continue;
        const txt = await r.text();
        try {
          const obj = JSON.parse(_relaxJson(txt));
          if (obj && typeof obj === "object") return obj;
        } catch {
          // invalid json, try next path
        }
      } catch {
        // ignore and try next path
      }
    }
    return {};
  })();

  return _bossIconMapPromise;
}

function cleanBossNameForIcons(name) {
  let value = cleanItemNameForIcons(name)
    // Hard mode boss activities may include the mode marker either before or
    // after the boss name, e.g. "(Hard mode) General Graardor" or
    // "General Graardor, a huge war chief. (Hard mode)".
    .replace(/\(\s*hard\s*mode\s*\)/ig, " ")
    .replace(/^[-–—:;,\.\s]+/, "")
    .replace(/[-–—:;,\.\s]+$/, "")
    .replace(/^the\s+/i, "The ")
    .replace(/\s+/g, " ")
    .trim();

  return value;
}

function addBossNameVariant(set, value) {
  const raw = cleanBossNameForIcons(value);
  if (!raw) return;

  set.add(raw);

  // Do not singularise full comma-containing boss names such as
  // "Zamorak, Lord of Chaos". A comma-stripped fallback is added separately.
  if (raw.includes(",")) return;

  // RuneMetrics commonly pluralises the final word when there is a count:
  // "Araxxis" -> "Araxxi", "General Graardors" -> "General Graardor".
  if (/ies$/i.test(raw)) set.add(raw.replace(/ies$/i, "y"));
  if (/s$/i.test(raw) && !/(ss|us|x)$/i.test(raw)) set.add(raw.replace(/s$/i, ""));
}

function bossNameVariants(name) {
  const raw = cleanBossNameForIcons(name);
  const variants = new Set();
  addBossNameVariant(variants, raw);

  // Some boss activity lines add flavour text after a comma, e.g.
  // "I killed 12 General Graardors, all huge war chiefs."
  // Keep the full value first for comma-containing boss names, but also try the
  // segment before the comma as a fallback.
  const commaBase = raw.split(",")[0]?.trim() || "";
  if (commaBase && commaBase !== raw) addBossNameVariant(variants, commaBase);

  return Array.from(variants).filter(Boolean);
}

function normaliseBossMapKey(name) {
  return _norm(String(name || "")
    .replace(/[’']/g, "")
    .replace(/\.$/, ""));
}

function bossIconCandidatesFromName(bossName) {
  const out = [];
  for (const variant of bossNameVariants(bossName)) {
    out.push(
      ...iconCandidates("assets/activity/monsters/", variant),
      ...iconCandidates("assets/activity/monsters/", toFileKey(variant)),
      ...iconCandidates("assets/activity/monsters/", toFileKey(variant).replace(/_/g, ""))
    );
  }
  return Array.from(new Set(out));
}

function bossMappedIconCandidates(iconPath) {
  if (!iconPath) return [];
  const raw = String(iconPath).trim();
  if (!raw) return [];

  const direct = (/^(https?:)?\//i.test(raw) || raw.startsWith("assets/"))
    ? _mappedIconCandidates(raw)
    : _mappedIconCandidates(`assets/activity/monsters/${raw}`);

  const fileName = raw.split(/[\\/]/).pop() || raw;
  const stem = fileName.replace(/\.(png|webp|jpe?g|svg)$/i, "");
  const stemCandidates = stem ? [
    ...iconCandidates("assets/activity/monsters/", stem),
    ...iconCandidates("assets/activity/monsters/", stem.replace(/[_-]+/g, " "))
  ] : [];

  return Array.from(new Set([...direct, ...stemCandidates]));
}

function findMappedBossIcon(bossName, bossIconMap) {
  if (!bossIconMap || typeof bossIconMap !== "object") return null;

  const wanted = bossNameVariants(bossName).map(normaliseBossMapKey);
  for (const [key, entry] of Object.entries(bossIconMap)) {
    if (!wanted.includes(normaliseBossMapKey(key))) continue;

    if (typeof entry === "string") return entry;
    if (entry && typeof entry === "object" && entry.icon) return entry.icon;
  }

  return null;
}

// Start fetching early (non-blocking)
loadBossIconMap();

function setImgWithFallback(imgEl, candidates, finalFallback) {
  if (!imgEl) return;

  let i = 0;
  const list = (candidates || []).slice();
  if (finalFallback) list.push(finalFallback);

  const tryNext = () => {
    if (i >= list.length) return;
    imgEl.src = list[i];
    i++;
  };

  imgEl.onerror = () => {
    if (i >= list.length) {
      imgEl.onerror = null;
      return;
    }
    tryNext();
  };

  tryNext();
}

function renderLastPull(el, lastPull) {
  if (!el) return;
  if (!lastPull || (!lastPull.local && !lastPull.utc)) {
    el.textContent = "";
    return;
  }
  const tz = lastPull.timezone || "UTC";
  const local = lastPull.local ? `${lastPull.local} (${tz})` : "—";
  const utc = lastPull.utc ? `${lastPull.utc} UTC` : "—";
  el.textContent = `Last data pull: ${local} • ${utc}`;
}

/* ---------------- Player avatar (cached server-side) ---------------- */

const DEFAULT_AVATAR_URL = "assets/avatars/default.png";

function setPlayerAvatar(rsn) {
  const img = qs("playerAvatar");
  if (!img) return;

  const name = String(rsn || "").trim();
  if (!name) {
    img.classList.add("hidden");
    img.removeAttribute("src");
    img.alt = "";
    return;
  }

  // Prefer the same local cached avatar path used by the clan overview. If the
  // cached file is missing, fall back to the avatar proxy so it can fetch/cache
  // a fresh copy, and finally to the default chat head.
  const apiName = name.replace(/\s+/g, "_");
  const candidates = [
    getCachedAvatarUrl(name),
    `api/avatar.php?player=${encodeURIComponent(apiName)}`,
    DEFAULT_AVATAR_URL,
  ];
  let index = 0;

  img.alt = `${name} avatar`;
  img.onload = () => img.classList.remove("hidden");
  img.onerror = () => {
    index += 1;
    if (index >= candidates.length) {
      img.classList.add("hidden");
      return;
    }
    img.src = candidates[index];
  };

  img.classList.add("hidden");
  img.src = candidates[index];
}

/* ---------------- Clan avatars (cached only) ---------------- */

// Mirror api/avatar.php filename rules (spaces preserved, unsafe filesystem chars replaced)
function avatarSafeFilename(rsn) {
  let s = String(rsn || "").trim();
  if (!s) return "unknown";
  s = s.replace(/\s+/g, "_");
  //s = s.replace(/[\\\/\:\*\?\"\<\>\|]+/g, "_");
  //s = s.replace(/\s+/g, " ").trim();
  return s || "unknown";
}

function getCachedAvatarUrl(rsn) {
  const safe = avatarSafeFilename(rsn);
  return `assets/avatars/${encodeURIComponent(safe)}.png`;
}

function getDefaultAvatarUrl() {
  return DEFAULT_AVATAR_URL;
}


/* ---------------- Activity icon logic ---------------- */

const SKILLS = [
  "Attack","Defence","Strength","Constitution","Ranged","Prayer","Magic",
  "Cooking","Woodcutting","Fletching","Fishing","Firemaking","Crafting","Smithing","Mining",
  "Herblore","Agility","Thieving","Slayer","Farming","Runecrafting","Hunter","Construction",
  "Summoning","Dungeoneering","Divination","Invention","Archaeology","Necromancy",
];

function findSkillInText(text) {
  const t = String(text || "").toLowerCase();
  for (const s of SKILLS) {
    const low = s.toLowerCase();
    const re = new RegExp(`\\b${low}\\b`, "i");
    if (re.test(t)) return s;
  }
  return null;
}

function cleanItemNameForIcons(name) {
  let s = String(name || "");
  if (s.normalize) s = s.normalize("NFKC");
  s = s.replace(/ /g, " ").replace(/\s+/g, " ").trim();
  s = s.replace(/^["'“”‘’]+|["'“”‘’]+$/g, "").trim();
  s = s.replace(/\.\s*$/, "").trim();
  return s;
}

const DEFAULT_ITEM_NAME_CLEANUP_RULES = {
  strip_prefixes: ["pair of"],
  strip_suffixes: [],
  replacements: [],
};

let _itemNameCleanupRules = DEFAULT_ITEM_NAME_CLEANUP_RULES;
let _itemNameCleanupRulesPromise = null;

function escapeRegExp(value) {
  return String(value || "").replace(/[.*+?^${}()|[\]\\]/g, "\\$&");
}

function normaliseItemCleanupRules(raw) {
  const merged = {
    strip_prefixes: [...DEFAULT_ITEM_NAME_CLEANUP_RULES.strip_prefixes],
    strip_suffixes: [...DEFAULT_ITEM_NAME_CLEANUP_RULES.strip_suffixes],
    replacements: [...DEFAULT_ITEM_NAME_CLEANUP_RULES.replacements],
  };

  if (!raw || typeof raw !== "object") return merged;

  for (const key of ["strip_prefixes", "strip_suffixes"]) {
    if (!Array.isArray(raw[key])) continue;
    for (const value of raw[key]) {
      const text = String(value || "").trim();
      if (text && !merged[key].some(existing => existing.toLowerCase() === text.toLowerCase())) {
        merged[key].push(text);
      }
    }
  }

  if (Array.isArray(raw.replacements)) {
    for (const rule of raw.replacements) {
      if (!rule || typeof rule !== "object") continue;
      const from = String(rule.from || "");
      if (!from) continue;
      merged.replacements.push({
        from,
        to: String(rule.to ?? ""),
        flags: String(rule.flags || "i"),
      });
    }
  }

  return merged;
}

function loadItemNameCleanupRules() {
  if (_itemNameCleanupRulesPromise) return _itemNameCleanupRulesPromise;

  _itemNameCleanupRulesPromise = fetch("assets/activity/item_name_cleanup.json", { cache: "no-cache" })
    .then(res => res.ok ? res.json() : null)
    .then(json => {
      _itemNameCleanupRules = normaliseItemCleanupRules(json);
      return _itemNameCleanupRules;
    })
    .catch(() => DEFAULT_ITEM_NAME_CLEANUP_RULES);

  return _itemNameCleanupRulesPromise;
}

function cleanDropItemNameForLookup(name, rules = _itemNameCleanupRules) {
  let value = cleanItemNameForIcons(name);
  const activeRules = normaliseItemCleanupRules(rules);

  for (const rule of activeRules.replacements || []) {
    try {
      const re = new RegExp(rule.from, rule.flags || "i");
      value = value.replace(re, rule.to ?? "");
    } catch {
      // Ignore invalid custom regex rules so one bad rule cannot break icons.
    }
  }

  for (const prefix of activeRules.strip_prefixes || []) {
    const re = new RegExp(`^${escapeRegExp(prefix)}\s+`, "i");
    value = value.replace(re, "");
  }

  for (const suffix of activeRules.strip_suffixes || []) {
    const re = new RegExp(`\s+${escapeRegExp(suffix)}$`, "i");
    value = value.replace(re, "");
  }

  return cleanItemNameForIcons(value);
}

// Start fetching early; defaults are used immediately until the JSON arrives.
loadItemNameCleanupRules();

function extractDropItemNameFromText(activityText) {
  const t = String(activityText || "").trim();
  const m = t.match(/^I found a[n]?\s+(.+?)(?:\.\s*)?$/i);
  return (m && m[1]) ? cleanDropItemNameForLookup(m[1]) : null;
}

function extractDropItemNameFromDetails(details) {
  const d = String(details || "").trim();
  const m = d.match(/\bdropped a[n]?\s+(.+?)(?:\.\s*|$)/i);
  return (m && m[1]) ? cleanDropItemNameForLookup(m[1]) : null;
}

function extractBossKillFromText(activityText) {
  const t = String(activityText || "").replace(/\s+/g, " ").trim();
  const m = t.match(/^I killed\s+(?:(\d[\d,]*)\s+)?(.+?)(?:\.\s*)?$/i);
  if (!m || !m[2]) return null;

  const bossName = cleanBossNameForIcons(m[2]);
  if (!bossName) return null;

  const bossCount = m[1] ? Number(String(m[1]).replace(/,/g, "")) : null;
  return { bossName, bossCount: Number.isFinite(bossCount) ? bossCount : null };
}

function classifyActivity(text, details) {
  const combined = `${text || ""} ${details || ""}`.toLowerCase();

  const bossKill = extractBossKillFromText(text);
  if (bossKill) return { kind: "boss", ...bossKill };

  if (combined.includes("has completed") || combined.includes("completed:") || combined.includes("quest")) {
    return { kind: "quest" };
  }

  const itemFromText = extractDropItemNameFromText(text);
  const itemFromDetails = extractDropItemNameFromDetails(details);
  const itemName = itemFromText || itemFromDetails;
  if (itemName) return { kind: "drop", itemName };

  if (combined.includes("levelled") || combined.includes("leveled") || combined.includes("level up") || combined.includes("i am now level")) {
    const skill = findSkillInText(details) || findSkillInText(text) || null;
    return { kind: "level", skillName: skill };
  }

  // Skill XP / skill-related activity (e.g. "54,000,000 XP in Archaeology")
  // If we can confidently detect a skill name and the text mentions XP/experience,
  // show that skill's icon for the activity row.
  if (combined.includes("xp") || combined.includes("experience")) {
    const skill = findSkillInText(details) || findSkillInText(text) || null;
    if (skill) return { kind: "skill_xp", skillName: skill };
  }

  // Any other activity that names a skill: treat it as skill-related for icon purposes.
  {
    const skill = findSkillInText(details) || findSkillInText(text) || null;
    if (skill) return { kind: "skill", skillName: skill };
  }

  return { kind: "default" };
}

/* ---------------- Clan overview state ---------------- */
let clanData = null;
let clanFilter = "all";
let selectedClanXpPeriod = "7d";
let clanXpView = "total"; // 'total' or 'leaders'

const clanSkillTopCache = new Map(); // key: `${period}|${skill}` -> array

function populateClanXpPeriods(periods, currentValue) {
  const sel = qs("clanXpPeriod");
  if (!sel) return;
  sel.innerHTML = (periods || []).map(p => {
    const v = p.value;
    const label = p.label;
    const selected = v === currentValue ? " selected" : "";
    return `<option value="${escapeHtml(v)}"${selected}>${escapeHtml(label)}</option>`;
  }).join("");
}


function populateRankFilter(members, keepValue = false) {
  const sel = qs("rankFilter");
  if (!sel) return;

  const current = String(sel.value || "all");
  const ranks = Array.from(new Set((members || [])
    .map(m => String(m?.rank_name ?? m?.rank ?? "").trim())
    .filter(r => r !== "")));

  // Sort ranks highest first so the dropdown follows the in-game clan hierarchy.
  ranks.sort(compareRanksDescending);

  sel.innerHTML = `<option value="all">All ranks</option>` + ranks.map(r => {
    const esc = escapeHtml(r);
    return `<option value="${esc}">${esc}</option>`;
  }).join("");

  if (keepValue && ranks.some(r => r === current)) {
    sel.value = current;
  } else {
    sel.value = "all";
  }
}

async function fetchClanTopEarnersForSkill(skillName) {
  const params = getParams();
  const clanKey = params.clan || params.configuredClan;
  if (!clanKey || !skillName) return null;

  const cacheKey = `${selectedClanXpPeriod}|${skillName.toLowerCase()}`;
  if (clanSkillTopCache.has(cacheKey)) return clanSkillTopCache.get(cacheKey);

  const url = `${API.clanOverview}?clan=${encodeURIComponent(clanKey)}&period=${encodeURIComponent(selectedClanXpPeriod)}&skill=${encodeURIComponent(skillName)}`;
  const data = await fetchJson(url);
  if (data && data.ok && Array.isArray(data.top_earners)) {
    clanSkillTopCache.set(cacheKey, data.top_earners);
    return data.top_earners;
  }
  return null;
}


function setClanXpView(view) {
  clanXpView = (String(view || "total").toLowerCase() === "leaders") ? "leaders" : "total";

  const tabs = qs("clanXpTabs");
  if (tabs) {
    tabs.querySelectorAll("button[data-xptab]").forEach(btn => {
      const v = String(btn.getAttribute("data-xptab") || "").toLowerCase();
      const isActive = v === clanXpView;
      btn.classList.toggle("active", isActive);
      btn.setAttribute("aria-selected", isActive ? "true" : "false");
    });
  }

  renderClanXpSection();
}

function renderClanXpSection() {
  if (clanXpView === "leaders") renderClanXpLeaders();
  else renderClanXpTotals();
}

function renderClanXpLeaders() {
  const grid = qs("clanSkillLeaders");
  const meta = qs("clanXpMeta");
  if (!grid || !meta || !clanData?.ok) return;

  const xp = clanData.xp || {};
  const leaders = xp.leaders_by_skill || [];

  const start = xp.start_utc || "";
  const end = xp.end_utc || "";
  meta.textContent = start && end ? `Window: ${start} -> ${end} UTC` : "";

  if (!leaders.length) {
    grid.innerHTML = `<div class="muted">No XP snapshot data yet for this period.</div>`;
    return;
  }

  grid.innerHTML = leaders.map(r => {
    const skill = r.skill || "—";
    const key = r.skill_key || skill;
    const rsn = r.rsn ? r.rsn : "—";
    const gained = r.has_data ? `${formatNumber(r.gained_xp)} XP` : "—";

    // Each tile is clickable and expands to show top 10 for that skill
    return `
      <div class="leaderTile" data-skill="${escapeHtml(skill)}" data-skillparam="${escapeHtml(key)}">
        <div class="leaderRow" role="button" aria-expanded="false">
          <img class="miniIcon" data-skill="${escapeHtml(skill)}" data-skillkey="${escapeHtml(key)}" alt="" />
          <div class="leaderSkill">${escapeHtml(skill)}</div>
          <div class="leaderMeta">
  <a href="?player=${encodeURIComponent(rsn)}" target="_blank" rel="noopener" class="leaderLink" onclick="event.stopPropagation()">${escapeHtml(rsn)}</a> • ${escapeHtml(gained)}
</div>
        </div>
        <div class="leaderExpand hidden">
          <div class="muted">Loading top earners…</div>
        </div>
      </div>
    `;
  }).join("");

  grid.querySelectorAll("img.miniIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });

  // click leader row -> expand top 10 list
  grid.querySelectorAll(".leaderTile").forEach(tile => {
    const row = tile.querySelector(".leaderRow");
    const expand = tile.querySelector(".leaderExpand");
    const skill = tile.getAttribute("data-skill") || "";
    const skillParam = tile.getAttribute("data-skillparam") || skill;

    row.addEventListener("click", async () => {
      // Close others
      grid.querySelectorAll(".leaderExpand").forEach(el => { if (el !== expand) el.classList.add("hidden"); });
      grid.querySelectorAll(".leaderRow").forEach(el => { if (el !== row) el.setAttribute("aria-expanded", "false"); });

      const isOpen = !expand.classList.contains("hidden");
      if (isOpen) {
        expand.classList.add("hidden");
        row.setAttribute("aria-expanded", "false");
        return;
      }

      expand.classList.remove("hidden");
      row.setAttribute("aria-expanded", "true");

      // If we've already rendered a list, don't refetch
      if (expand.getAttribute("data-loaded") === "1") return;

      expand.innerHTML = `<div class="muted">Loading top earners…</div>`;
      try {
        const list = await fetchClanTopEarnersForSkill(skillParam);
        if (!list || !list.length) {
          expand.innerHTML = `<div class="muted">No XP gains recorded for ${escapeHtml(skill)} in this period.</div>`;
          expand.setAttribute("data-loaded", "1");
          return;
        }

        const rowsHtml = list.slice(0, 10).map((p, idx) => {
          const n = idx + 1;
          const rsn = p.rsn || "—";
          const gainedXp = p.gained_xp ?? null;
          return `
            <div style="display:flex; gap:10px; align-items:center; padding:6px 0; border-top:1px solid rgba(255,255,255,0.06);">
              <div style="width:22px; text-align:right; font-weight:900;">${n}.</div>
              <div style="font-weight:800;"><a href="?player=${encodeURIComponent(rsn)}" target="_blank" rel="noopener" class="leaderLink">${escapeHtml(rsn)}</a></div>
              <div class="muted" style="margin-left:auto; font-weight:800;">+${formatNumber(gainedXp)} XP</div>
            </div>
          `;
        }).join("");

        expand.innerHTML = `
          <div style="display:flex; align-items:baseline; gap:10px;">
            <div style="font-weight:900;">Top 10 • ${escapeHtml(skill)}</div>
            <div class="muted" style="margin-left:auto;">Period: ${escapeHtml(selectedClanXpPeriod)}</div>
          </div>
          <div style="margin-top:8px;">
            ${rowsHtml}
          </div>
        `;
        expand.setAttribute("data-loaded", "1");
      } catch (e) {
        expand.innerHTML = `<div class="muted">Couldn’t load earners for this skill.</div>`;
      }
    });
  });
}



function renderClanXpTotals() {
  const grid = qs("clanSkillLeaders");
  const meta = qs("clanXpMeta");
  if (!grid || !meta || !clanData?.ok) return;

  const xp = clanData.xp || {};
  const totals = xp.totals_by_skill || xp.total_by_skill || xp.total_clan_xp_by_skill || [];

  const start = xp.start_utc || "";
  const end = xp.end_utc || "";
  meta.textContent = start && end ? `Window: ${start} -> ${end} UTC` : "";

  if (!Array.isArray(totals) || !totals.length) {
    grid.innerHTML = `<div class="muted">No XP snapshot data yet for this period.</div>`;
    return;
  }

  grid.innerHTML = totals.map(r => {
    const skill = r.skill || "—";
    const key = r.skill_key || skill;
    const gained = r.has_data ? `${formatNumber(r.gained_xp)} XP` : "—";

    return `
      <div class="leaderTile" data-skill="${escapeHtml(skill)}" data-skillparam="${escapeHtml(key)}">
        <div class="leaderRow" role="button" aria-expanded="false">
          <img class="miniIcon" data-skill="${escapeHtml(skill)}" data-skillkey="${escapeHtml(key)}" alt="" />
          <div class="leaderSkill">${escapeHtml(skill)}</div>
          <div class="leaderMeta">Clan total • ${escapeHtml(gained)}</div>
        </div>
        <div class="leaderExpand hidden">
          <div class="muted">Loading top earners…</div>
        </div>
      </div>
    `;
  }).join("");

  grid.querySelectorAll("img.miniIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });

  // Optional drilldown: click to show top 10 earners for that skill (same as leaders view)
  grid.querySelectorAll(".leaderTile").forEach(tile => {
    const row = tile.querySelector(".leaderRow");
    const expand = tile.querySelector(".leaderExpand");
    const skill = tile.getAttribute("data-skill") || "";
    const skillParam = tile.getAttribute("data-skillparam") || skill;

    row.addEventListener("click", async () => {
      // Close others
      grid.querySelectorAll(".leaderExpand").forEach(el => { if (el !== expand) el.classList.add("hidden"); });
      grid.querySelectorAll(".leaderRow").forEach(el => { if (el !== row) el.setAttribute("aria-expanded", "false"); });

      const isOpen = !expand.classList.contains("hidden");
      if (isOpen) {
        expand.classList.add("hidden");
        row.setAttribute("aria-expanded", "false");
        return;
      }

      expand.classList.remove("hidden");
      row.setAttribute("aria-expanded", "true");

      if (expand.getAttribute("data-loaded") === "1") return;

      expand.innerHTML = `<div class="muted">Loading top earners…</div>`;
      try {
        const list = await fetchClanTopEarnersForSkill(skillParam);
        if (!list || !list.length) {
          expand.innerHTML = `<div class="muted">No XP gains recorded for ${escapeHtml(skill)} in this period.</div>`;
          expand.setAttribute("data-loaded", "1");
          return;
        }

        const rowsHtml = list.slice(0, 10).map((p, idx) => {
          const n = idx + 1;
          const rsn = p.rsn || "—";
          const gainedXp = p.gained_xp ?? null;
          return `
            <div style="display:flex; gap:10px; align-items:center; padding:6px 0; border-top:1px solid rgba(255,255,255,0.06);">
              <div style="width:22px; text-align:right; font-weight:900;">${n}.</div>
              <div style="font-weight:800;"><a href="?player=${encodeURIComponent(rsn)}" target="_blank" rel="noopener" class="leaderLink">${escapeHtml(rsn)}</a></div>
              <div class="muted" style="margin-left:auto; font-weight:800;">+${formatNumber(gainedXp)} XP</div>
            </div>
          `;
        }).join("");

        expand.innerHTML = `
          <div style="display:flex; align-items:baseline; gap:10px;">
            <div style="font-weight:900;">Top 10 • ${escapeHtml(skill)}</div>
            <div class="muted" style="margin-left:auto;">Period: ${escapeHtml(selectedClanXpPeriod)}</div>
          </div>
          <div style="margin-top:8px;">
            ${rowsHtml}
          </div>
        `;
        expand.setAttribute("data-loaded", "1");
      } catch (e) {
        expand.innerHTML = `<div class="muted">Couldn’t load earners for this skill.</div>`;
      }
    });
  });
}

function setFilter(newFilter) {
  clanFilter = String(newFilter || "all").trim().toLowerCase();

  // Keep rank dropdown in sync: if filter is not a rank filter, reset dropdown to "All ranks"
  const rf = qs("rankFilter");
  if (rf) {
    const nf = String(newFilter || "all").trim();
    if (!/^rank[:=]/i.test(nf) && nf.toLowerCase() !== "rank") {
      rf.value = "all";
    }
  }

  document.querySelectorAll(".segBtn").forEach(btn => {
    const bf = String(btn.dataset.filter || "").trim().toLowerCase();
    btn.classList.toggle("active", bf === clanFilter);
  });

  renderMemberList();
}

function renderMemberList() {
  if (!clanData || !clanData.ok) return;

  const needle = normalise(qs("memberSearch").value).toLowerCase();
  const listEl = qs("memberList");

  let members = Array.isArray(clanData.members) ? [...clanData.members] : [];

  const f = String(clanFilter || "all").trim().toLowerCase();

  // Be tolerant of schema differences in member objects
  const getCapped = (m) => !!(m?.capped ?? m?.has_capped ?? m?.is_capped ?? false);
  const getVisited = (m) => !!(m?.visited ?? m?.has_visited ?? m?.is_visited ?? m?.visited_this_week ?? false);
  const getRank = (m) => String(m?.rank_name ?? m?.rank ?? "").trim();

  if (f === "capped") {
    members = members.filter(m => getCapped(m));
  } else if (f === "uncapped") {
    members = members.filter(m => !getCapped(m));
  } else if (f === "private") {
    members = members.filter(m => !!(m?.is_private ?? m?.private ?? false));
  } else if (f === "guests" || f === "guest") {
    members = members.filter(m => getRank(m).toLowerCase() === "guest");
  } else if (f === "visitedonly" || f === "visited_only" || f === "visited-only") {
    members = members.filter(m => getVisited(m) && !getCapped(m));
  } else if (f.startsWith("rank:") || f.startsWith("rank=")) {
    const wanted = f.split(/[:=]/)[1] || "";
    members = members.filter(m => getRank(m).toLowerCase() === wanted.toLowerCase());
  } else if (f !== "all" && f !== "") {
    // Treat any other filter value as a rank name (e.g., "Admin", "Recruit")
    members = members.filter(m => getRank(m).toLowerCase() === f);
  }

  if (needle) {
    members = members.filter(m =>
      (m.rsn || "").toLowerCase().includes(needle) ||
      (m.rank_name || "").toLowerCase().includes(needle)
    );
  }

  members.sort(compareClanMembersDescending);

  qs("clanStatus").textContent = `${members.length} shown`;

  listEl.innerHTML = members.map(m => {
    const isCapped = !!(m?.capped ?? m?.has_capped ?? m?.is_capped ?? false);
    const isVisited = !!(m?.visited ?? m?.has_visited ?? m?.is_visited ?? m?.visited_this_week ?? false);
    const badge = isCapped ? "Capped" : (isVisited ? "Visited" : "Uncapped");

    const rank = getRank(m);
    const titleHtml = memberTitleRankHtml(m.rsn, rank);
    let privateHtml = "";

    const isPrivate = !!(m?.is_private ?? m?.private ?? false);
    const sinceLocal = (m?.private_since_local || "").trim();
    if (isPrivate) {
      const since = sinceLocal ? ` since ${escapeHtml(sinceLocal)}` : "";
      privateHtml = `<div class="memberMeta"><span class="pill private" title="Profile is private">Private${since}</span></div>`;
    }

    return `
      <div class="memberCard clickable" data-rsn="${escapeHtml(m.rsn)}" title="Open player">
        <div class="memberLeft">
          <div class="memberHeader">
            <img class="memberAvatar" src="${getCachedAvatarUrl(m.rsn)}" alt="" onerror="if(this.dataset.fallbackApplied==='1'){return;} this.dataset.fallbackApplied='1'; this.src='${getDefaultAvatarUrl()}';" />
            <div class="memberIdentity">
              ${titleHtml}
              ${privateHtml}
            </div>
          </div>
        </div>
        <div class="badge">${badge}</div>
      </div>
    `;
  }).join("");

  Array.from(listEl.querySelectorAll(".memberCard.clickable")).forEach(node => {
    node.addEventListener("click", () => {
      const rsn = node.getAttribute("data-rsn") || "";
      if (rsn) setQuery({ player: rsn });
    });
  });
}

async function loadClanOverview(clanKey, period) {
  clanData = null;
  qs("clanSubheading").textContent = "Loading…";
  qs("clanSubheading").classList.remove("hidden");
  setText("statMembersTotal", "—");
  setText("statMembersPrivate", "—");
  setText("statMembersNew", "—");
  setText("statCappingCapped", "—");
  setText("statCappingUncapped", "—");
  setText("statCappingPercent", "—");
  qs("clanStatus").textContent = "";
  qs("memberList").innerHTML = "";
  qs("clanLastPull").textContent = "";
  if (qs("clanWeekDetails")) qs("clanWeekDetails").textContent = "";
  clearClanResetCountdown();
  if (qs("clanXpMeta")) qs("clanXpMeta").textContent = "";
  if (qs("clanSkillLeaders")) qs("clanSkillLeaders").innerHTML = "";

  const usePeriod = period || selectedClanXpPeriod || "7d";
  const data = await fetchJson(`${API.clanOverview}?clan=${encodeURIComponent(clanKey)}&period=${encodeURIComponent(usePeriod)}`);
  if (!data || !data.ok) {
    qs("clanSubheading").textContent = `Error: ${data?.error || "request failed"}`;
    qs("clanStatus").textContent = data?.hint ? `Hint: ${data.hint}` : "";
    return;
  }

  clanData = data;

  // Populate rank filter dropdown based on returned member ranks
  populateRankFilter(data.members, false);

  const clanName = data.clan?.name || clanKey;
  const tz = data.week?.timezone || "UTC";
  const ws = data.week?.week_start_local || "";
  const we = data.week?.week_end_local || "";

  qs("clanSubheading").textContent = "";
  qs("clanSubheading").classList.add("hidden");

  const activeMembers = data.stats?.active_members ?? "0";
  const privateMembers = (data.stats?.private_profiles ?? null) ?? ((data.members || []).filter(m => !!(m?.is_private ?? m?.private ?? false)).length);
  const newMembers = data.stats?.new_members_this_week;
  const cappedMembers = data.stats?.capped ?? "0";
  const uncappedMembers = data.stats?.uncapped ?? "0";
  const cappedPercent = data.stats?.percent_capped ?? "0";

  setText("statMembersTotal", `${String(activeMembers)} active`);
  setText("statMembersPrivate", String(privateMembers));
  setText("statMembersNew", newMembers === null || newMembers === undefined ? "—" : String(newMembers));
  setText("statCappingCapped", String(cappedMembers));
  setText("statCappingUncapped", String(uncappedMembers));
  setText("statCappingPercent", `${String(cappedPercent)}% capped`);

  renderLastPull(qs("clanLastPull"), data.last_pull);
  const clanWeekDetails = qs("clanWeekDetails");
  if (clanWeekDetails) clanWeekDetails.textContent = `${clanName} • Week: ${ws} → ${we} (${tz})`;
  setClanResetCountdown(data.week);
  selectedClanXpPeriod = data.xp?.period || usePeriod;
  populateClanXpPeriods(data.xp_periods || [], selectedClanXpPeriod);
  setClanXpView("total");
  renderMemberList();
}

/* ---------------- Player state ---------------- */
let playerData = null;
let selectedXpPeriod = "7d";
let selectedActivityLimit = 20;
let selectedSkillView = "current";
let selectedJournalView = "activity";
let selectedXpLineChartSkills = new Set(SKILLS);
let selectedXpDailyChartSkills = new Set(SKILLS);
const ACTIVITY_LIMIT_OPTIONS = [20, 50, 100, 200];

function normaliseActivityLimit(value) {
  const n = Number(value);
  return ACTIVITY_LIMIT_OPTIONS.includes(n) ? n : 20;
}

function populateXpPeriods(periods, currentValue) {
  const sel = qs("xpPeriod");
  sel.innerHTML = (periods || []).map(p => {
    const v = p.value;
    const label = p.label;
    const selected = v === currentValue ? " selected" : "";
    return `<option value="${escapeHtml(v)}"${selected}>${escapeHtml(label)}</option>`;
  }).join("");
}

function populateActivityLimitOptions(options, currentValue) {
  const sel = qs("activityLimit");
  if (!sel) return;

  const optionValues = (Array.isArray(options) && options.length ? options : ACTIVITY_LIMIT_OPTIONS)
    .map(v => normaliseActivityLimit(v))
    .filter((value, index, arr) => arr.indexOf(value) === index);

  const current = normaliseActivityLimit(currentValue);
  selectedActivityLimit = current;

  sel.innerHTML = optionValues.map(value => {
    const selected = value === current ? " selected" : "";
    return `<option value="${value}"${selected}>${value}</option>`;
  }).join("");
}

function currentXpPeriodLabel() {
  const sel = qs("xpPeriod");
  const selected = sel?.selectedOptions?.[0]?.textContent?.trim();
  if (selected) return selected;
  return String(playerData?.xp?.period || selectedXpPeriod || "selected period");
}


function formatCompactNumber(n) {
  if (n === null || n === undefined) return "—";
  const x = Number(n);
  if (!Number.isFinite(x)) return "—";
  try {
    return new Intl.NumberFormat("en-AU", {
      notation: "compact",
      maximumFractionDigits: x >= 1_000_000 ? 1 : 0,
    }).format(x);
  } catch {
    return formatNumber(x);
  }
}

function formatShortDateFromUtc(value) {
  const ms = parseUtcDateToMs(value);
  if (!Number.isFinite(ms)) return "—";
  try {
    return new Intl.DateTimeFormat("en-AU", { day: "numeric", month: "short" }).format(new Date(ms));
  } catch {
    return String(value || "—");
  }
}

function setJournalView(view) {
  selectedJournalView = view === "xpstats" || view === "drops" ? view : "activity";

  qs("journalTabs")?.querySelectorAll("[data-journal-view]").forEach(btn => {
    const active = btn.getAttribute("data-journal-view") === selectedJournalView;
    btn.classList.toggle("active", active);
    btn.setAttribute("aria-selected", active ? "true" : "false");
  });

  const title = qs("journalTitle");
  if (title) title.textContent = "Activity Journal";

  show(qs("activityJournalView"), selectedJournalView === "activity");
  show(qs("xpStatsView"), selectedJournalView === "xpstats");
  show(qs("dropHistoryView"), selectedJournalView === "drops");

  const limitWrap = qs("activityLimitWrap");
  if (limitWrap) {
    limitWrap.classList.toggle("reservedHidden", selectedJournalView !== "activity");
  }

  if (selectedJournalView === "xpstats") renderXpStats();
  if (selectedJournalView === "drops") renderDropHistory();
}

function xpSkillSelectionForChart(chartKey) {
  const key = chartKey === "daily" ? "daily" : "line";
  if (key === "daily") {
    if (!(selectedXpDailyChartSkills instanceof Set)) selectedXpDailyChartSkills = new Set(SKILLS);
    if (selectedXpDailyChartSkills.size === 0) selectedXpDailyChartSkills = new Set(SKILLS);
    return selectedXpDailyChartSkills;
  }

  if (!(selectedXpLineChartSkills instanceof Set)) selectedXpLineChartSkills = new Set(SKILLS);
  if (selectedXpLineChartSkills.size === 0) selectedXpLineChartSkills = new Set(SKILLS);
  return selectedXpLineChartSkills;
}

function getVisibleXpSkills(chartKey = "line") {
  const selected = xpSkillSelectionForChart(chartKey);
  const visible = SKILLS.filter(skill => selected.has(skill));
  return visible.length ? visible : SKILLS.slice();
}

function renderXpSkillFilters(chartKey = "line", title = "Skill filters") {
  const selected = xpSkillSelectionForChart(chartKey);
  return `
    <div class="xpSkillFilters" role="group" aria-label="${escapeHtml(title)}">
      ${SKILLS.map(skill => {
        const active = selected.has(skill);
        return `
          <button class="xpSkillFilterBtn ${active ? "active" : ""}" type="button" data-xp-chart="${escapeHtml(chartKey)}" data-xp-skill="${escapeHtml(skill)}" title="${escapeHtml(active ? `Hide ${skill}` : `Show ${skill}`)}">
            <img class="xpSkillFilterIcon" data-skill="${escapeHtml(skill)}" data-skillkey="${escapeHtml(skill)}" alt="${escapeHtml(skill)}" />
          </button>
        `;
      }).join("")}
    </div>
  `;
}

function updateXpSkillFilterButton(btn, chartKey, skill) {
  const selected = xpSkillSelectionForChart(chartKey);
  const active = selected.has(skill);
  btn.classList.toggle("active", active);
  btn.setAttribute("title", active ? `Hide ${skill}` : `Show ${skill}`);
}

function rerenderXpChart(chartKey) {
  const key = chartKey === "daily" ? "daily" : "line";
  const stats = playerData?.xp_stats || null;
  const mount = qs(key === "daily" ? "xpDailyChartSvgMount" : "xpLineChartSvgMount");
  if (!mount) return;

  mount.innerHTML = key === "daily"
    ? renderXpDailySkillChartBody(stats?.daily_skill_xp_30d || [])
    : renderXpLineChartBody(Array.isArray(stats?.points) ? stats.points : []);
}

function wireXpSkillFilters(root) {
  const scope = root || document;
  scope.querySelectorAll("button.xpSkillFilterBtn").forEach(btn => {
    btn.addEventListener("click", () => {
      const chartKey = btn.getAttribute("data-xp-chart") || "line";
      const skill = btn.getAttribute("data-xp-skill") || "";
      if (!skill) return;

      const selected = xpSkillSelectionForChart(chartKey);
      if (selected.has(skill)) {
        if (selected.size <= 1) return;
        selected.delete(skill);
      } else {
        selected.add(skill);
      }

      updateXpSkillFilterButton(btn, chartKey, skill);
      rerenderXpChart(chartKey);
    });
  });
}

function wireSkillIconFallbacks(root) {
  const scope = root || document;
  scope.querySelectorAll("img.xpSkillIcon, img.xpSkillFilterIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillName),
      ...iconCandidates("assets/skills/", skillKey),
      "assets/skills/_default.png",
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });
}


function getChartLocalTooltip(target) {
  const card = target?.closest?.(".xpChartCard");
  if (!card) return null;

  let tooltip = card.querySelector(".chartLocalTooltip");
  if (!tooltip) {
    tooltip = document.createElement("div");
    tooltip.className = "chartLocalTooltip hidden";
    tooltip.setAttribute("role", "tooltip");
    card.appendChild(tooltip);
  }

  return tooltip;
}

function chartTooltipHtml(text) {
  return String(text || "")
    .split(/\n+/)
    .filter(Boolean)
    .map((line, index) => index === 0
      ? `<div class="chartHoverTitle">${escapeHtml(line)}</div>`
      : `<div>${escapeHtml(line)}</div>`)
    .join("");
}

function positionChartTooltip(event, tooltip) {
  if (!event || !tooltip) return;

  const card = tooltip.closest(".xpChartCard");
  if (!card) return;

  const rect = card.getBoundingClientRect();
  const gap = 12;
  const edgePad = 8;
  const localX = event.clientX - rect.left;
  const localY = event.clientY - rect.top;

  // Measure after content has been written and the tooltip is visible.
  const tooltipWidth = Math.max(tooltip.offsetWidth || 0, 140);
  const tooltipHeight = Math.max(tooltip.offsetHeight || 0, 44);

  let x = localX + gap;
  let y = localY + gap;

  // Keep the tooltip inside the chart card so it does not get clipped by the
  // right/bottom edge. This mirrors the compact skill-card tooltip behaviour,
  // but follows the pointer within the graph.
  if (x + tooltipWidth > rect.width - edgePad) {
    x = localX - tooltipWidth - gap;
  }
  if (y + tooltipHeight > rect.height - edgePad) {
    y = localY - tooltipHeight - gap;
  }

  x = Math.max(edgePad, Math.min(x, rect.width - tooltipWidth - edgePad));
  y = Math.max(edgePad, Math.min(y, rect.height - tooltipHeight - edgePad));

  tooltip.style.left = `${x}px`;
  tooltip.style.top = `${y}px`;
}

function showChartTooltip(event, target) {
  const text = target?.getAttribute("data-chart-tooltip") || "";
  if (!text) return;

  const tooltip = getChartLocalTooltip(target);
  if (!tooltip) return;

  tooltip.innerHTML = chartTooltipHtml(text);
  tooltip.classList.remove("hidden");
  positionChartTooltip(event, tooltip);
}

function hideChartTooltips(root) {
  const scope = root || document;
  scope.querySelectorAll(".chartLocalTooltip").forEach(tooltip => tooltip.classList.add("hidden"));
}

function wireChartTooltips() {
  document.addEventListener("pointerover", (event) => {
    const target = event.target?.closest?.("[data-chart-tooltip]");
    if (!target) return;
    showChartTooltip(event, target);
  });

  document.addEventListener("pointermove", (event) => {
    const target = event.target?.closest?.("[data-chart-tooltip]");
    if (!target) return;
    const tooltip = getChartLocalTooltip(target);
    if (!tooltip || tooltip.classList.contains("hidden")) showChartTooltip(event, target);
    else positionChartTooltip(event, tooltip);
  });

  document.addEventListener("pointerout", (event) => {
    const target = event.target?.closest?.("[data-chart-tooltip]");
    if (!target) return;
    const next = event.relatedTarget?.closest?.("[data-chart-tooltip]");
    if (next === target) return;
    const card = target.closest?.(".xpChartCard");
    hideChartTooltips(card || document);
  });

  document.addEventListener("scroll", () => hideChartTooltips(document), true);
  window.addEventListener("blur", () => hideChartTooltips(document));
}

function renderXpLineChartBody(points) {
  const rows = Array.isArray(points) ? points : [];
  const usableRows = rows.filter(p => p && typeof p === "object");

  if (usableRows.length < 2) {
    return `<div class="xpStatsEmpty muted">Not enough XP snapshots yet to draw a trend for this period.</div>`;
  }

  const visibleSkills = getVisibleXpSkills("line");
  const width = 740;
  const height = 280;
  const padLeft = 54;
  const padRight = 18;
  const padTop = 24;
  const padBottom = 38;
  const plotW = width - padLeft - padRight;
  const plotH = height - padTop - padBottom;

  const skillValue = (row, skill) => {
    const value = row?.skills?.[skill];
    if (Number.isFinite(Number(value))) return Math.max(0, Number(value));
    return 0;
  };

  const maxY = Math.max(
    ...usableRows.flatMap(row => visibleSkills.map(skill => skillValue(row, skill))),
    1
  );

  const firstLabel = formatShortDateFromUtc(usableRows[0]?.captured_at_utc);
  const lastLabel = formatShortDateFromUtc(usableRows[usableRows.length - 1]?.captured_at_utc);
  const midY = padTop + plotH / 2;

  const lineHtml = visibleSkills.map(skill => {
    const pointsStr = usableRows.map((row, i) => {
      const x = padLeft + (usableRows.length === 1 ? 0 : (i / (usableRows.length - 1)) * plotW);
      const y = padTop + plotH - (skillValue(row, skill) / maxY) * plotH;
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    }).join(" ");

    const finalGain = skillValue(usableRows[usableRows.length - 1], skill);
    const lastX = padLeft + plotW;
    const lastY = padTop + plotH - (finalGain / maxY) * plotH;

    const tooltip = `${skill}\n+${formatNumber(finalGain)} XP over ${currentXpPeriodLabel()}`;

    return `
      <g class="xpSkillLineGroup">
        <polyline class="xpSkillChartLine" style="stroke:${escapeHtml(xpSkillColour(skill))}" points="${escapeHtml(pointsStr)}" data-chart-tooltip="${escapeHtml(tooltip)}"></polyline>
        <polyline class="xpSkillChartHitLine" points="${escapeHtml(pointsStr)}" data-chart-tooltip="${escapeHtml(tooltip)}"></polyline>
        <circle class="xpSkillChartPoint" cx="${lastX.toFixed(1)}" cy="${lastY.toFixed(1)}" r="3.2" style="fill:${escapeHtml(xpSkillColour(skill))}" data-chart-tooltip="${escapeHtml(tooltip)}"></circle>
        <circle class="xpSkillChartPointHit" cx="${lastX.toFixed(1)}" cy="${lastY.toFixed(1)}" r="10" data-chart-tooltip="${escapeHtml(tooltip)}"></circle>
      </g>
    `;
  }).join("");

  return `
      <svg class="xpLineChart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Per-skill XP gained trend chart">
        <line class="xpChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${padTop}" y2="${padTop}" />
        <line class="xpChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${midY}" y2="${midY}" />
        <line class="xpChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${height - padBottom}" y2="${height - padBottom}" />
        <text class="xpChartAxis" x="${padLeft - 8}" y="${padTop + 4}" text-anchor="end">${escapeHtml(formatCompactNumber(maxY))}</text>
        <text class="xpChartAxis" x="${padLeft - 8}" y="${height - padBottom + 4}" text-anchor="end">0</text>
        <text class="xpChartAxis" x="${padLeft}" y="${height - 10}" text-anchor="start">${escapeHtml(firstLabel)}</text>
        <text class="xpChartAxis" x="${width - padRight}" y="${height - 10}" text-anchor="end">${escapeHtml(lastLabel)}</text>
        ${lineHtml}
      </svg>
  `;
}

function renderXpLineChart(points) {
  return `
    <div class="xpChartCard">
      <div class="xpChartTitleRow">
        <div class="xpChartTitle">XP gained by skill over ${escapeHtml(currentXpPeriodLabel())}</div>
      </div>
      ${renderXpSkillFilters("line", "Toggle skills in the XP gained chart")}
      <div id="xpLineChartSvgMount">${renderXpLineChartBody(points)}</div>
      <div class="chartLocalTooltip hidden" role="tooltip"></div>
    </div>
  `;
}


function normaliseSkillGainRows(rows, includeZero = true) {
  const list = Array.isArray(rows) ? rows : [];
  return list
    .map(row => ({
      skill: String(row?.skill || "Skill"),
      skill_key: String(row?.skill_key || row?.skill || "skill"),
      gained_xp: Number.isFinite(Number(row?.gained_xp)) ? Number(row.gained_xp) : 0,
    }))
    .filter(row => includeZero || row.gained_xp > 0)
    .sort((a, b) => {
      const diff = Number(b.gained_xp || 0) - Number(a.gained_xp || 0);
      if (diff !== 0) return diff;
      return a.skill.localeCompare(b.skill);
    });
}

function renderSkillGainSummary(label, row) {
  if (!row) {
    return `
      <div class="xpStatsCard">
        <div class="xpStatsLabel">${escapeHtml(label)}</div>
        <div class="xpStatsValue small">—</div>
      </div>
    `;
  }

  return `
    <div class="xpStatsCard xpSkillSummaryCard">
      <div class="xpStatsLabel">${escapeHtml(label)}</div>
      <div class="xpFavouriteSkill">
        <img class="xpSkillIcon" data-skill="${escapeHtml(row.skill)}" data-skillkey="${escapeHtml(row.skill_key)}" alt="${escapeHtml(row.skill)}" />
        <div>
          <div class="xpFavouriteSkillName">${escapeHtml(row.skill)}</div>
          <div class="xpFavouriteSkillValue">+${escapeHtml(formatNumber(row.gained_xp || 0))} XP</div>
        </div>
      </div>
    </div>
  `;
}

function renderXpSkillBars(skillRows, options = {}) {
  const rows = normaliseSkillGainRows(skillRows, options.includeZero !== false);
  if (!rows.length) {
    return `<div class="xpStatsEmpty muted">No skill XP gains were detected for this period.</div>`;
  }

  const max = Math.max(...rows.map(r => Number(r.gained_xp || 0)), 1);
  return `
    <div class="xpSkillBars ${options.compact ? "compact" : ""}">
      ${rows.map((row, index) => {
        const skill = row.skill || "Skill";
        const key = row.skill_key || skill;
        const gain = Number(row.gained_xp || 0);
        const width = gain > 0 ? Math.max(3, Math.round((gain / max) * 100)) : 0;
        return `
          <div class="xpSkillBarRow">
            <div class="xpSkillRank">${index + 1}</div>
            <img class="xpSkillIcon" data-skill="${escapeHtml(skill)}" data-skillkey="${escapeHtml(key)}" alt="${escapeHtml(skill)}" />
            <div class="xpSkillBarMain">
              <div class="xpSkillBarMeta"><span>${escapeHtml(skill)}</span><strong>+${escapeHtml(formatNumber(gain))}</strong></div>
              <div class="xpSkillBarTrack"><div class="xpSkillBarFill" style="width:${width}%"></div></div>
            </div>
          </div>
        `;
      }).join("")}
    </div>
  `;
}

function xpSkillColour(skill) {
  const index = Math.max(0, SKILLS.findIndex(s => s.toLowerCase() === String(skill || "").toLowerCase()));
  const hue = (index * 47) % 360;
  return `hsl(${hue} 58% 62%)`;
}

function renderXpDailySkillChartBody(days) {
  const rows = Array.isArray(days) ? days : [];
  if (!rows.length) {
    return `<div class="xpStatsEmpty muted">No daily XP data is available yet.</div>`;
  }

  const visibleSkills = getVisibleXpSkills("daily");
  const width = 740;
  const height = 280;
  const padLeft = 52;
  const padRight = 16;
  const padTop = 24;
  const padBottom = 36;
  const plotW = width - padLeft - padRight;
  const plotH = height - padTop - padBottom;
  const dayTotal = (day) => visibleSkills.reduce((sum, skill) => sum + Number(day?.skills?.[skill] || 0), 0);
  const maxY = Math.max(...rows.map(day => dayTotal(day)), 1);
  const gap = 3;
  const barW = Math.max(4, (plotW / Math.max(rows.length, 1)) - gap);
  const bottomY = padTop + plotH;

  const barHtml = rows.map((day, i) => {
    const x = padLeft + i * (plotW / Math.max(rows.length, 1)) + gap / 2;
    let usedH = 0;
    const segments = visibleSkills.map(skill => {
      const value = Number(day?.skills?.[skill] || 0);
      if (value <= 0) return "";
      const h = Math.max(1, (value / maxY) * plotH);
      const y = bottomY - usedH - h;
      usedH += h;
      const tooltip = `${day?.label || day?.date || "Day"}\n${skill}: +${formatNumber(value)} XP`;
      const hitPadX = 3;
      const hitH = Math.max(10, h);
      const hitY = Math.max(padTop, y - ((hitH - h) / 2));
      return `
        <rect class="xpDailyChartSegment" x="${x.toFixed(1)}" y="${y.toFixed(1)}" width="${barW.toFixed(1)}" height="${h.toFixed(1)}" rx="2" fill="${escapeHtml(xpSkillColour(skill))}"></rect>
        <rect class="xpDailyChartHitSegment" x="${(x - hitPadX).toFixed(1)}" y="${hitY.toFixed(1)}" width="${(barW + hitPadX * 2).toFixed(1)}" height="${hitH.toFixed(1)}" data-chart-tooltip="${escapeHtml(tooltip)}"></rect>
      `;
    }).join("");

    const showLabel = i === 0 || i === rows.length - 1 || i % 7 === 0;
    return `
      ${segments}
      ${showLabel ? `<text class="xpChartAxis" x="${(x + barW / 2).toFixed(1)}" y="${height - 10}" text-anchor="middle">${escapeHtml(day?.label || "")}</text>` : ""}
    `;
  }).join("");

  return `
      <svg class="xpDailyChart" viewBox="0 0 ${width} ${height}" role="img" aria-label="Stacked daily skill XP chart for the last 30 days">
        <line class="xpChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${padTop}" y2="${padTop}" />
        <line class="xpChartGrid" x1="${padLeft}" x2="${width - padRight}" y1="${bottomY}" y2="${bottomY}" />
        <text class="xpChartAxis" x="${padLeft - 8}" y="${padTop + 4}" text-anchor="end">${escapeHtml(formatCompactNumber(maxY))}</text>
        <text class="xpChartAxis" x="${padLeft - 8}" y="${bottomY + 4}" text-anchor="end">0</text>
        ${barHtml}
      </svg>
  `;
}

function renderXpDailySkillChart(days) {
  return `
    <div class="xpChartCard">
      <div class="xpChartTitle">XP earned per day — last 30 days</div>
      ${renderXpSkillFilters("daily", "Toggle skills in the daily XP chart")}
      <div id="xpDailyChartSvgMount">${renderXpDailySkillChartBody(days)}</div>
      <div class="chartLocalTooltip hidden" role="tooltip"></div>
    </div>
  `;
}


function renderXpStats() {
  const el = qs("xpStatsView");
  if (!el) return;

  const stats = playerData?.xp_stats || null;
  const xp = playerData?.xp || null;
  const points = Array.isArray(stats?.points) ? stats.points : [];
  const periodSkillGains = normaliseSkillGainRows(stats?.skill_gains || xp?.skill_gains || [], true);
  const positiveGains = periodSkillGains.filter(row => Number(row.gained_xp || 0) > 0);
  const favourite = positiveGains[0] || null;
  const leastFavourite = periodSkillGains.length
    ? periodSkillGains.slice().sort((a, b) => {
        const diff = Number(a.gained_xp || 0) - Number(b.gained_xp || 0);
        if (diff !== 0) return diff;
        return a.skill.localeCompare(b.skill);
      })[0]
    : null;

  const totalGain = Number.isFinite(Number(stats?.gained_total_xp))
    ? Number(stats.gained_total_xp)
    : (Number.isFinite(Number(xp?.gained_total_xp)) ? Number(xp.gained_total_xp) : null);

  el.innerHTML = `
    <div class="xpStatsSummary">
      <div class="xpStatsCard">
        <div class="xpStatsLabel">${escapeHtml(currentXpPeriodLabel())} XP</div>
        <div class="xpStatsValue">${totalGain === null ? "—" : `+${escapeHtml(formatNumber(totalGain))}`}</div>
      </div>
      ${renderSkillGainSummary("Favourite skill", favourite)}
      ${renderSkillGainSummary("Least favourite skill", leastFavourite)}
    </div>
    <div id="xpLineChartMount">${renderXpLineChart(points)}</div>
    <div id="xpDailyChartMount">${renderXpDailySkillChart(stats?.daily_skill_xp_30d || [])}</div>
    <div class="xpStatsSectionTitle">Per Skill XP — ${escapeHtml(currentXpPeriodLabel())}</div>
    ${renderXpSkillBars(periodSkillGains, { includeZero: true })}
  `;

  wireSkillIconFallbacks(el);
  wireXpSkillFilters(el);
}

function renderDropHistory() {
  const el = qs("dropHistoryView");
  if (!el) return;

  const history = playerData?.drop_history || {};
  const items = Array.isArray(history?.items) ? history.items : [];
  const total = Number.isFinite(Number(history?.total_detected)) ? Number(history.total_detected) : 0;
  const unique = Number.isFinite(Number(history?.unique_items)) ? Number(history.unique_items) : items.length;

  if (!items.length) {
    el.innerHTML = `<div class="xpStatsEmpty muted">No item drops have been detected for this player yet.</div>`;
    return;
  }

  el.innerHTML = `
    <div class="dropHistorySummary">
      <div class="xpStatsCard">
        <div class="xpStatsLabel">Detected drops</div>
        <div class="xpStatsValue">${escapeHtml(formatNumber(total))}</div>
      </div>
      <div class="xpStatsCard">
        <div class="xpStatsLabel">Unique items</div>
        <div class="xpStatsValue">${escapeHtml(formatNumber(unique))}</div>
      </div>
    </div>
    <div class="dropHistoryTableWrap">
      <table class="dropHistoryTable">
        <thead>
          <tr>
            <th scope="col">Drop name</th>
            <th scope="col">Last seen</th>
            <th scope="col" class="num">Drop count</th>
          </tr>
        </thead>
        <tbody>
          ${items.map(item => {
            const name = String(item?.item_name || "Unknown drop");
            const count = Number.isFinite(Number(item?.count)) ? Number(item.count) : 0;
            const lastSeen = item?.last_seen_local || item?.last_seen_utc || "—";
            return `
              <tr>
                <td class="dropHistoryNameCell">${escapeHtml(name)}</td>
                <td>${escapeHtml(lastSeen)}</td>
                <td class="num">${escapeHtml(formatNumber(count))}</td>
              </tr>
            `;
          }).join("")}
        </tbody>
      </table>
    </div>
  `;
}


function buildSkillGainMap() {
  const map = new Map();
  const rows = playerData?.xp?.skill_gains || [];
  if (!Array.isArray(rows)) return map;

  rows.forEach(row => {
    const gained = Number(row?.gained_xp);
    const value = Number.isFinite(gained) ? gained : 0;
    [row?.skill, row?.skill_key].forEach(key => {
      const norm = String(key || "").trim().toLowerCase();
      if (norm) map.set(norm, value);
    });
  });

  return map;
}

function skillGainFor(skillName, skillKey, gainMap) {
  const keys = [skillName, skillKey].map(v => String(v || "").trim().toLowerCase()).filter(Boolean);
  for (const key of keys) {
    if (gainMap.has(key)) return gainMap.get(key);
  }
  return playerData?.xp?.has_data ? 0 : null;
}

function renderCurrentSkills() {
  const gridEl = qs("skillsGrid");
  const cs = playerData?.current_skills;

  if (!cs || !cs.has_data) {
    gridEl.innerHTML = `<div class="muted">No skill snapshot data yet.</div>`;
    return;
  }

  const skills = (cs.skills || [])
    .filter(s => s && !s.__is_total)
    .sort(compareSkillsByIdAscending);

  const gainMap = buildSkillGainMap();
  const periodLabel = currentXpPeriodLabel();

  gridEl.innerHTML = skills.map(s => {
    const isTotal = !!(s && s.__is_total);
    const name = isTotal ? "Total Level" : (s.skill || "—");
    const key = isTotal ? "total" : (s.skill_key || name);
    const levelRaw = (s.level === null || s.level === undefined) ? null : Number(s.level);
    const xp = (s.xp === null || s.xp === undefined) ? null : Number(s.xp);

    let displayLevel = Number.isFinite(levelRaw) && levelRaw > 0 ? levelRaw : null;
    let isVirtualShown = false;
    let tierClass = isTotal ? "total" : "";
    let badgeHtml = "";

    if (!isTotal) {
      const vLevel = (window.TrackerSkills && typeof window.TrackerSkills.virtualLevelFromXp === 'function')
        ? window.TrackerSkills.virtualLevelFromXp(Number(xp || 0), name)
        : displayLevel;

      const maxV = (window.TrackerSkills && typeof window.TrackerSkills.maxVirtualLevelForSkill === 'function')
        ? window.TrackerSkills.maxVirtualLevelForSkill(name)
        : 120;

      displayLevel = Math.max(Number(displayLevel || 0), Number(vLevel || 0)) || null;
      isVirtualShown = !!(displayLevel && levelRaw && displayLevel > levelRaw);

      const is200m = Number(xp || 0) >= 200_000_000;
      const isMaxVirtual = displayLevel >= maxV;

      if (is200m) tierClass = "gold";
      else if (displayLevel >= 120) tierClass = "silver";
      else if (displayLevel >= 99) tierClass = "bronze";

      if (is200m) {
        badgeHtml = `<img class="skillBadge" src="assets/badges/200m.png" alt="200m" />`;
      } else if (isMaxVirtual) {
        badgeHtml = `<img class="skillBadge" src="assets/badges/max_virtual.png" alt="Max virtual" />`;
      }
    }

    const gained = isTotal
      ? (playerData?.xp?.has_data ? Number(playerData?.xp?.gained_total_xp || 0) : null)
      : skillGainFor(name, key, gainMap);

    const levelText = displayLevel ? String(displayLevel) : "—";
    const xpText = formatNumber(xp);
    const gainedText = gained === null || gained === undefined ? "—" : `+${formatNumber(gained)}`;
    const virtualNote = isVirtualShown ? "Virtual level shown" : "";
    const tooltipLines = [
      name,
      `Level: ${levelText}${isVirtualShown ? " (virtual)" : ""}`,
      `Total XP: ${xpText}`,
      `${periodLabel} XP: ${gainedText}`,
    ].filter(Boolean);

    return `
      <div class="skillCard compact ${escapeHtml(tierClass)}" tabindex="0" title="${escapeHtml(tooltipLines.join("\n"))}" aria-label="${escapeHtml(tooltipLines.join(". "))}">
        <div class="skillIconWrap">
          <img class="skillIcon" data-skill="${escapeHtml(name)}" data-skillkey="${escapeHtml(key)}" ${isTotal ? 'src="assets/skills/total.png"' : ''} alt="${escapeHtml(name)}" />
          ${badgeHtml}
        </div>
        <div class="skillCompactLevel">${escapeHtml(levelText)}</div>
        <div class="skillHoverTip" role="tooltip">
          <div class="skillHoverTitle">${escapeHtml(name)}</div>
          <div>Level: <strong>${escapeHtml(levelText)}${isVirtualShown ? " virtual" : ""}</strong></div>
          <div>Total XP: <strong>${escapeHtml(xpText)}</strong></div>
          <div>${escapeHtml(periodLabel)} XP: <strong>${escapeHtml(gainedText)}</strong></div>
          ${virtualNote ? `<div class="skillHoverMuted">${escapeHtml(virtualNote)}</div>` : ""}
        </div>
      </div>
    `;
  }).join("");

  gridEl.querySelectorAll("img.skillIcon").forEach(img => {
    if (img.getAttribute("src")) return;
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });
}

function renderTopXpSkills() {
  const listEl = qs("skillList");
  if (!listEl) return;

  const xp = playerData?.xp;
  const top = (xp?.top_skills || []).slice(0, 10);

  if (!xp || !xp.has_data) {
    listEl.innerHTML = `<div class="muted">No XP snapshot data for this period yet.</div>`;
    return;
  }

  if (!top.length) {
    listEl.innerHTML = `<div class="muted">No XP gains recorded for this period.</div>`;
    return;
  }

  listEl.innerHTML = top.map((row, index) => {
    const name = row.skill || "—";
    const key = row.skill_key || name;
    const gained = row.gained_xp ?? null;

    return `
      <div class="skillRow topXpSkillRow">
        <div class="topXpRank">${formatNumber(index + 1)}</div>
        <img class="miniIcon" data-skill="${escapeHtml(name)}" data-skillkey="${escapeHtml(key)}" alt="" />
        <div class="skillName">${escapeHtml(name)}</div>
        <div class="skillXp">+${formatNumber(gained)}</div>
      </div>
    `;
  }).join("");

  listEl.querySelectorAll("img.miniIcon").forEach(img => {
    const skillName = img.getAttribute("data-skill") || "";
    const skillKey = img.getAttribute("data-skillkey") || "";
    const candidates = [
      ...iconCandidates("assets/skills/", skillKey),
      ...iconCandidates("assets/skills/", skillName),
    ];
    setImgWithFallback(img, candidates, "assets/skills/_default.png");
  });
}

function normaliseSkillView(view) {
  return String(view || "").toLowerCase() === "topxp" ? "topxp" : "current";
}

function updateSkillPanelView() {
  const view = normaliseSkillView(selectedSkillView);
  selectedSkillView = view;

  const currentSkills = qs("skillsGrid");
  const topXpSkills = qs("skillList");
  show(currentSkills, view === "current");
  show(topXpSkills, view === "topxp");

  const titleEl = qs("skillPanelTitle");
  if (titleEl) titleEl.textContent = "Skills/XP";

  const hintEl = qs("skillPanelHint");
  if (hintEl) {
    hintEl.textContent = "";
    hintEl.classList.add("hidden");
  }

  qs("skillViewToggle")?.querySelectorAll("button[data-skill-view]").forEach(btn => {
    const isActive = normaliseSkillView(btn.getAttribute("data-skill-view")) === view;
    btn.classList.toggle("active", isActive);
    btn.setAttribute("aria-selected", isActive ? "true" : "false");
  });
}

function setSkillView(view) {
  selectedSkillView = normaliseSkillView(view);
  updateSkillPanelView();
}

function renderSkillPanel() {
  renderCurrentSkills();
  renderTopXpSkills();
  updateSkillPanelView();
}


function statIconPath(name) {
  return `assets/stats/${name}.png`;
}

function skillIdForSort(skillRow) {
  const numericCandidates = [
    skillRow?.skill_id,
    skillRow?.id,
    skillRow?.skill_index,
    skillRow?.skill_order,
    skillRow?.order,
  ];

  for (const value of numericCandidates) {
    const n = Number(value);
    if (Number.isFinite(n)) return n;
  }

  const name = String(skillRow?.skill || skillRow?.name || "").trim().toLowerCase();
  const configured = window.TrackerSkills?.SKILLS || [];
  const idx = configured.findIndex(s => String(s?.name || "").trim().toLowerCase() === name);
  return idx >= 0 ? idx + 1 : 9999;
}

function compareSkillsByIdAscending(a, b) {
  const aid = skillIdForSort(a);
  const bid = skillIdForSort(b);
  if (aid !== bid) return aid - bid;
  return String(a?.skill || a?.name || "").localeCompare(String(b?.skill || b?.name || ""));
}

function getCurrentSkillLevel(skillName) {
  const target = String(skillName || "").trim().toLowerCase();
  const rows = playerData?.current_skills?.skills || [];
  const found = rows.find(row => String(row?.skill || "").trim().toLowerCase() === target);
  const level = Number(found?.level);
  return Number.isFinite(level) && level > 0 ? level : null;
}

function getTotalLevelForPlayer() {
  const direct = Number(playerData?.current_skills?.total?.level);
  if (Number.isFinite(direct) && direct > 0) return direct;

  const rows = playerData?.current_skills?.skills || [];
  let total = 0;
  let counted = 0;
  for (const row of rows) {
    const level = Number(row?.level);
    if (Number.isFinite(level) && level > 0) {
      total += level;
      counted += 1;
    }
  }
  return counted ? total : null;
}

function calculateCombatLevelFromCurrentSkills() {
  const direct = Number(
    playerData?.combat_level ??
    playerData?.member?.combat_level ??
    playerData?.current_skills?.combat_level
  );
  if (Number.isFinite(direct) && direct > 0) return Math.floor(direct);

  const level = (skill, fallback = 1, cap = 99) => {
    const raw = getCurrentSkillLevel(skill);
    const n = Number.isFinite(Number(raw)) && Number(raw) > 0 ? Number(raw) : fallback;
    return Math.min(cap, Math.max(1, Math.floor(n)));
  };

  // RS3 combat-level formula, including Necromancy as its own combat style.
  // Non-necromancy combat skills are capped to 99 here to avoid inflated values
  // from virtual levels in historical RuneMetrics/HiScores snapshots.
  const attack = level("Attack", 1, 99);
  const strength = level("Strength", 1, 99);
  const defence = level("Defence", 1, 99);
  const constitution = level("Constitution", 10, 99);
  const ranged = level("Ranged", 1, 99);
  const prayer = level("Prayer", 1, 99);
  const magic = level("Magic", 1, 99);
  const summoning = level("Summoning", 1, 99);
  const necromancy = level("Necromancy", 1, 120);

  const offence = Math.max(attack + strength, magic * 2, ranged * 2, necromancy * 2);
  const combat = (1.3 * offence + defence + constitution + Math.floor(prayer / 2) + Math.floor(summoning / 2)) / 4;
  return Math.max(3, Math.floor(combat));
}

function scoreRowValue(row) {
  if (!row || typeof row !== "object") return null;
  const value = Number(row.score);
  return Number.isFinite(value) ? value : null;
}

function scoreRowRankNote(row) {
  if (!row || typeof row !== "object") return "";
  const rank = Number(row.rank);
  if (Number.isFinite(rank) && rank > 0) return `Rank ${formatNumber(rank)}`;
  if (row.ranked === false || rank === -1) return "Unranked";
  return "";
}

function getQuestPointsForPlayer() {
  const q = playerData?.quests;
  if (!q || !q.ok) return null;
  const qp = Number(q?.totals?.quest_points_completed);
  return Number.isFinite(qp) ? qp : null;
}

function renderPlayerStatBlock() {
  const block = qs("playerStatBlock");
  if (!block) return;

  const summary = playerData?.hiscores_lite?.summary || {};
  const clues = summary.clues || {};
  const statRows = [
    { label: "Combat Level", value: calculateCombatLevelFromCurrentSkills(), icon: "combat" },
    { label: "Total Level", value: getTotalLevelForPlayer(), icon: "total_level" },
    { label: "RuneScore", value: scoreRowValue(summary.runescore), icon: "runescore" },
    { label: "Quest Points", value: getQuestPointsForPlayer(), icon: "quest_points" },
    { label: "Easy Clues", value: scoreRowValue(clues.easy), icon: "clue_easy" },
    { label: "Medium Clues", value: scoreRowValue(clues.medium), icon: "clue_medium" },
    { label: "Hard Clues", value: scoreRowValue(clues.hard), icon: "clue_hard" },
    { label: "Elite Clues", value: scoreRowValue(clues.elite), icon: "clue_elite" },
    { label: "Master Clues", value: scoreRowValue(clues.master), icon: "clue_master" },
  ];

  block.innerHTML = statRows.map(row => `
    <div class="playerStatTile">
      <div class="playerStatIconWrap">
        <img class="playerStatIcon" src="${escapeHtml(statIconPath(row.icon))}" alt="" />
      </div>
      <div class="playerStatText">
        <div class="playerStatLabel">${escapeHtml(row.label)}</div>
        <div class="playerStatValue">${formatNumber(row.value)}</div>
      </div>
    </div>
  `).join("");
}

function renderPlayerStatBlockLoading() {
  const block = qs("playerStatBlock");
  if (!block) return;

  const labels = [
    "Combat Level",
    "Total Level",
    "RuneScore",
    "Quest Points",
    "Easy Clues",
    "Medium Clues",
    "Hard Clues",
    "Elite Clues",
    "Master Clues",
  ];

  block.innerHTML = labels.map(label => `
    <div class="playerStatTile loading">
      <div class="playerStatIconWrap"><span class="playerStatIconPlaceholder" aria-hidden="true"></span></div>
      <div class="playerStatText">
        <div class="playerStatLabel">${escapeHtml(label)}</div>
        <div class="playerStatValue">—</div>
      </div>
    </div>
  `).join("");
}


function renderQuests() {
  const metaEl = qs("questMeta");
  const statusEl = qs("questStatus");
  const listEl = qs("questList");
  if (!metaEl || !statusEl || !listEl) return;

  const q = playerData?.quests;
  if (!q) {
    metaEl.textContent = "—";
    statusEl.textContent = "";
    listEl.innerHTML = "";
    return;
  }

  if (!q.ok) {
    const http = q.http_code ? ` (HTTP ${q.http_code})` : "";
    metaEl.textContent = `Unable to load quests${http}`;
    statusEl.textContent = q.error ? String(q.error) : "";
    listEl.innerHTML = q.hint ? `<div class="muted">${escapeHtml(String(q.hint))}</div>` : "";
    return;
  }

  const totals = q.totals || {};
  const total = Number(totals.total ?? 0);
  const completed = Number(totals.completed ?? 0);
  const started = Number(totals.started ?? 0);
  const notStarted = Number(totals.not_started ?? 0);
  metaEl.textContent =
    `Total: ${total} • Completed: ${completed} • In progress: ${started} • Not started: ${notStarted}`;

  const quests = Array.isArray(q.quests) ? q.quests : [];
  statusEl.textContent = `${quests.length} quests`;

  if (!quests.length) {
    listEl.innerHTML = `<div class="muted">No quest data returned.</div>`;
    return;
  }

  // Map status -> icon + colour (as requested)
  function statusInfo(statusRaw) {
    const s = String(statusRaw || "").toUpperCase();
    if (s === "COMPLETED") return { key: "completed", icon: "✓" };
    if (s === "STARTED" || s === "IN_PROGRESS") return { key: "started", icon: "⚠" };
    return { key: "not_started", icon: "✗" };
  }

  // Difficulty mapping (RuneMetrics)
  function difficultyLabel(diffRaw) {
    const n = Number(diffRaw);
    if (n === 0) return "Novice";
    if (n === 1) return "Intermediate";
    if (n === 2) return "Experienced";
    if (n === 3) return "Master";
    if (n === 4) return "Grandmaster";
    if (n === 250) return "Special";
    if (diffRaw === "Miniquest") return "Miniquest";
    return "Unknown";
  }


  // Group by difficulty (collapsible)
  const groups = new Map();
  for (const row of quests) {
    const titleText = String(row?.title || "");
    const isMiniquest = /\(miniquest\)/i.test(titleText);

    const diff = isMiniquest
      ? "Miniquest"
      : ((row && row.difficulty !== undefined && row.difficulty !== null && String(row.difficulty).trim() !== "")
          ? Number(row.difficulty)
          : "Unknown");

    if (!groups.has(diff)) groups.set(diff, []);
    groups.get(diff).push(row);
  }

  // Sort groups: numeric difficulty asc, Unknown last, then alpha
  const groupKeys = Array.from(groups.keys()).sort((a, b) => {
    const order = { 0: 0, 1: 1, 2: 2, 3: 3, 4: 4, 250: 5, "Miniquest": 6, "Unknown": 99 };

    const ao = (a in order) ? order[a] : (Number.isFinite(Number(a)) ? Number(a) : 50);
    const bo = (b in order) ? order[b] : (Number.isFinite(Number(b)) ? Number(b) : 50);

    if (ao !== bo) return ao - bo;

    return String(a).localeCompare(String(b));
  });

  const statusOrder = { not_started: 0, started: 1, completed: 2 };

  listEl.innerHTML = groupKeys.map((key) => {
    const rows = groups.get(key) || [];

    const sorted = rows.slice().sort((a, b) => {
      const ai = statusInfo(a?.status);
      const bi = statusInfo(b?.status);
      const ao = statusOrder[ai.key] ?? 9;
      const bo = statusOrder[bi.key] ?? 9;
      if (ao !== bo) return ao - bo;
      const at = String(a?.title || "").toLowerCase();
      const bt = String(b?.title || "").toLowerCase();
      return at.localeCompare(bt);
    });

    const inner = sorted.map((row) => {
      const title = String(row?.title || "—");
      const st = statusInfo(row?.status);

      // Instead of showing "Completed/Started/Not started", show useful info on the right.
      const qp = row?.questPoints !== undefined && row?.questPoints !== null ? `${formatNumber(Number(row.questPoints))} QP` : "";
      const mem = row?.members === true ? "Members" : (row?.members === false ? "Free" : "");
      const rhs = [qp, mem].filter(Boolean).join(" • ");

      return `
        <div class="questRow skillRow">
          <div class="skillName questTitle" style="font-weight:800;">
            <span class="questStatusIcon ${escapeHtml(st.key)}" aria-hidden="true">${escapeHtml(st.icon)}</span>${escapeHtml(title)}
          </div>
          <div class="skillVal">${escapeHtml(rhs)}</div>
        </div>
      `;
    }).join("");

    const label = `Difficulty: ${escapeHtml(difficultyLabel(key))}`;
    return `
      <details class="questGroup" style="margin-top:10px;">
        <summary style="cursor:pointer; font-weight:800;">
          ${label} <span class="muted" style="font-weight:600;">(${sorted.length})</span>
        </summary>
        <div class="skillList" style="margin-top:8px;">
          ${inner}
        </div>
      </details>
    `;
  }).join("");
}




function renderPlayer() {
  if (!playerData || !playerData.ok) return;

  const m = playerData.member;
  const c = playerData.clan;
  const tzLabel = (c && c.timezone) ? c.timezone : "";
  const week = playerData.week;

  qs("playerName").textContent = m?.rsn || "—";
  setPlayerAvatar(m?.rsn || "");
  qs("playerSubheading").textContent = "";
  qs("playerSubheading").classList.add("hidden");

  const rank = m?.rank_name ? m.rank_name : "—";
  const rankDisplay = qs("playerRankDisplay");
  if (rankDisplay) {
    rankDisplay.innerHTML = playerRankPillHtml(rank, c?.name || TRACKER_CONFIG.brandShortName || "Clan");
  }

  const metaEl = qs("playerMeta");
  if (metaEl) {
    const isPrivate = !!(m?.is_private ?? m?.private ?? false);
    const sinceLocal = (m?.private_since_local || "").trim();
    if (isPrivate) {
      const since = sinceLocal ? ` since ${escapeHtml(sinceLocal)}` : "";
      metaEl.innerHTML = `<span class="pill private" title="Profile is private">Private profile${since}</span>`;
      metaEl.classList.remove("hidden");
    } else {
      metaEl.innerHTML = "";
      metaEl.classList.add("hidden");
    }
  }

  const weekDetails = qs("playerWeekDetails");
  if (weekDetails) {
    const clanName = c?.name || c?.key || "Clan";
    const start = week?.week_start_local || "";
    const end = week?.week_end_local || "";
    const timezone = week?.timezone || c?.timezone || "UTC";
    weekDetails.textContent = `${clanName} • Week: ${start} → ${end} (${timezone})`;
  }

  renderPlayerStatBlock();
  renderSkillPanel();

  renderQuests();

  // Activity log (icons + coloured rows)
  const activityList = qs("activityList");
  const activity = playerData.recent_activity || [];
  populateActivityLimitOptions(playerData.activity_limit_options, playerData.activity_limit || selectedActivityLimit);
  const shownLimit = normaliseActivityLimit(playerData.activity_limit || selectedActivityLimit);
  if (qs("activityStatus")) qs("activityStatus").textContent = "";

  if (activity.length) {
    activityList.innerHTML = activity.map((a, i) => {
      const when = a.activity_date_local || a.activity_date_utc || a.announced_at_local || a.announced_at_utc || "";
      const text = formatNumbersInText(a.text || "");
      const details = formatNumbersInText(a.details || "");

      const info = classifyActivity(text, details);

      const rowClass =
        info.kind === "drop"  ? "activityRow activity-drop"  :
        info.kind === "level" ? "activityRow activity-level" :
        info.kind === "quest" ? "activityRow activity-quest" :
        info.kind === "boss"  ? "activityRow activity-boss"  :
                                "activityRow";

      return `
        <div class="${rowClass}">
          <img class="miniIcon"
               data-idx="${i}"
               data-kind="${escapeHtml(info.kind)}"
               data-skill="${escapeHtml(info.skillName || "")}"
               data-item="${escapeHtml(info.itemName || "")}"
               data-boss="${escapeHtml(info.bossName || "")}"
               alt="" />
          <div class="activityMain">
            <div class="activityText">${escapeHtml(text)}</div>
            ${details ? `<div class="activityDetails">${escapeHtml(details)}</div>` : ""}
            <div class="activityDate">${escapeHtml(when)} ${escapeHtml(tzLabel || "UTC")}</div>
          </div>
        </div>
      `;
    }).join("");

    activityList.querySelectorAll("img.miniIcon").forEach(img => {
      const kind = img.getAttribute("data-kind") || "default";
      const skillName = img.getAttribute("data-skill") || "";
      const itemName = cleanDropItemNameForLookup(img.getAttribute("data-item") || "");
      const bossName = cleanBossNameForIcons(img.getAttribute("data-boss") || "");

      if (kind === "level") {
        const candidates = skillName ? [...iconCandidates("assets/skills/", skillName)] : [];
        candidates.push("assets/activity/level.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "skill_xp" || kind === "skill") {
        const candidates = skillName ? [...iconCandidates("assets/skills/", skillName)] : [];
        candidates.push("assets/activity/default.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "boss") {
        const candidates = bossName ? bossIconCandidatesFromName(bossName) : [];
        candidates.push("assets/activity/default.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "drop") {
        const candidates = [];
        if (itemName) {
          candidates.push(`/api/wiki_item_icon.php?item=${encodeURIComponent(itemName)}`);
          const underscored = itemName.replace(/\s+/g, "_");
          candidates.push(`https://runescape.wiki/images/${encodeURIComponent(underscored)}.png`);
          candidates.push(
            ...iconCandidates("assets/items/", itemName),
            ...iconCandidates("assets/items/", toFileKey(itemName)),
            ...iconCandidates("assets/items/", toFileKey(itemName).replace(/_/g, ""))
          );
        }
        candidates.push("assets/activity/default.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
        return;
      }

      if (kind === "quest") {
        setImgWithFallback(img, ["assets/activity/quest.png"], "assets/activity/default.png");
        return;
      }

      setImgWithFallback(img, ["assets/activity/default.png"], "assets/activity/default.png");
    });

    // Re-apply drop icons once configurable item-name cleanup rules have loaded.
    loadItemNameCleanupRules().then(() => {
      activityList.querySelectorAll('img.miniIcon[data-kind="drop"]').forEach(img => {
        const itemName = cleanDropItemNameForLookup(img.getAttribute("data-item") || "");
        const candidates = [];
        if (itemName) {
          candidates.push(`/api/wiki_item_icon.php?item=${encodeURIComponent(itemName)}`);
          const underscored = itemName.replace(/\s+/g, "_");
          candidates.push(`https://runescape.wiki/images/${encodeURIComponent(underscored)}.png`);
          candidates.push(
            ...iconCandidates("assets/items/", itemName),
            ...iconCandidates("assets/items/", toFileKey(itemName)),
            ...iconCandidates("assets/items/", toFileKey(itemName).replace(/_/g, ""))
          );
        }
        candidates.push("assets/activity/default.png");
        setImgWithFallback(img, candidates, "assets/activity/default.png");
      });
    });

    // Apply boss icon mappings from assets/activity/monsters/icon_map.json.
    loadBossIconMap().then(bossIconMap => {
      if (!bossIconMap) return;
      activityList.querySelectorAll('img.miniIcon[data-kind="boss"]').forEach(img => {
        const bossName = cleanBossNameForIcons(img.getAttribute("data-boss") || "");
        const mapped = findMappedBossIcon(bossName, bossIconMap);
        if (mapped) {
          setImgWithFallback(
            img,
            [...bossMappedIconCandidates(mapped), ...bossIconCandidatesFromName(bossName)],
            "assets/activity/default.png"
          );
        }
      });
    });

    // Apply JSON icon overrides (if any mappings match)
    loadActivityIconMap().then(iconMap => {
      if (!iconMap) return;
      const list = playerData?.recent_activity || [];
      activityList.querySelectorAll("img.miniIcon").forEach(img => {
        const idx = Number(img.getAttribute("data-idx") || "NaN");
        if (!Number.isFinite(idx)) return;
        const a = list[idx];
        const mapped = findMappedActivityIcon(a, iconMap);
        if (mapped) setImgWithFallback(img, _mappedIconCandidates(mapped), "assets/activity/default.png");
      });
    });
  } else {
    activityList.innerHTML = `<div class="muted">No recent activity recorded.</div>`;
  }

  renderLastPull(qs("playerLastPull"), playerData.last_pull);
  setJournalView(selectedJournalView);
}

async function loadPlayer(rsn, period) {
  playerData = null;

  qs("playerSubheading").textContent = "Loading…";
  qs("playerSubheading").classList.remove("hidden");
  qs("playerName").textContent = "—";
  setPlayerAvatar("");
  if (qs("playerMeta")) {
    qs("playerMeta").textContent = "";
    qs("playerMeta").classList.add("hidden");
  }
  if (qs("playerRankDisplay")) qs("playerRankDisplay").innerHTML = "";
  qs("playerError").textContent = "";
  qs("playerLastPull").textContent = "";
  if (qs("playerWeekDetails")) qs("playerWeekDetails").textContent = "";
  renderPlayerStatBlockLoading();

  if (qs("questMeta")) qs("questMeta").textContent = "Loading quests...";
  if (qs("questStatus")) qs("questStatus").textContent = "";
  if (qs("questList")) qs("questList").innerHTML = "";
  if (qs("skillPanelHint")) { qs("skillPanelHint").textContent = ""; qs("skillPanelHint").classList.add("hidden"); }
  if (qs("skillsGrid")) qs("skillsGrid").innerHTML = "";
  if (qs("skillList")) qs("skillList").innerHTML = "";
  updateSkillPanelView();
  if (qs("activityStatus")) qs("activityStatus").textContent = "";
  if (qs("activityList")) qs("activityList").innerHTML = "";
  if (qs("xpStatsView")) qs("xpStatsView").innerHTML = "";
  if (qs("dropHistoryView")) qs("dropHistoryView").innerHTML = "";

  const activityLimit = normaliseActivityLimit(selectedActivityLimit);
  const url = `${API.player}?player=${encodeURIComponent(rsn)}&period=${encodeURIComponent(period || "7d")}&activity_limit=${encodeURIComponent(activityLimit)}`;
  const data = await fetchJson(url);

  if (!data || !data.ok) {
    qs("playerSubheading").classList.remove("hidden");
    qs("playerSubheading").textContent = `Error: ${data?.error || "request failed"}`;
    qs("playerError").textContent = data?.hint ? `Hint: ${data.hint}` : "";
    return;
  }

  playerData = data;
  selectedXpPeriod = data.xp?.period || period || "7d";
  selectedActivityLimit = normaliseActivityLimit(data.activity_limit || activityLimit);
  populateXpPeriods(data.xp_periods || [], selectedXpPeriod);
  populateActivityLimitOptions(data.activity_limit_options, selectedActivityLimit);
  renderPlayer();

  // If we haven't collected XP recently, trigger a refresh for this player.
  // Then reload the player data once.
  try {
    const key = String(rsn || "").trim().toLowerCase();
    if (!key) return;

    const lastSnapUtc =
      data?.last_pull?.sources?.last_xp_snapshot_utc ||
      data?.current_skills?.captured_at_utc ||
      null;

    const age = utcAgeSeconds(lastSnapUtc);
    const needsRefresh = (age === null) || (age > 300);

    if (needsRefresh && !xpRefreshAttempted.has(key)) {
      xpRefreshAttempted.add(key);

      const rsnForRefresh = String(rsn || "").trim().replace(/\s+/g, "_");
      const refreshUrl = `${API.refreshPlayerXp}?rsn=${encodeURIComponent(rsnForRefresh)}`;
      const refresh = await fetchJson(refreshUrl);

      if (refresh && refresh.ok && refresh.refreshed) {
        // re-load with the same period
        loadPlayer(rsn, selectedXpPeriod);
      }
    }
  } catch {
    // ignore refresh errors
  }
}

/* ---------------- Render views ---------------- */
function render() {
  const { clan, player, configuredClan } = getParams();

  const landing = qs("landingCard");
  const viewClan = qs("viewClan");
  const viewPlayer = qs("viewPlayer");
  const notice = qs("notice");

  // Pages such as Help include the shared menu/search script but do not contain
  // the clan/player view panels. Leave their content alone and let the top bar
  // search redirect back to index when a character is selected.
  if (!viewClan || !viewPlayer) {
    if (landing) show(landing, true);
    return;
  }

  if (player) {
    clearClanResetCountdown();
    show(landing, false);
    show(viewClan, false);
    show(viewPlayer, true);
    loadPlayer(player, selectedXpPeriod);
    return;
  }

  const clanToOpen = clan || configuredClan;
  if (clanToOpen) {
    show(landing, false);
    show(viewPlayer, false);
    show(viewClan, true);
    show(qs("backFromClan"), !!clan);
    loadClanOverview(clanToOpen, selectedClanXpPeriod);
    return;
  }

  clearClanResetCountdown();
  show(viewClan, false);
  show(viewPlayer, false);
  show(landing, true);
  if (notice) notice.textContent = "Set TRACKER_CLAN_ID in .env to open the clan overview automatically.";
}

/* ---------------- Typeahead component + wiring ---------------- */
/* (unchanged from your current file except the row class above) */

function createTypeahead({ inputEl, listEl, minChars = 1, maxItems = 8, fetchItems, renderItem, onSelectValue, onSelectItem }) {
  let items = [];
  let activeIndex = -1;
  let lastQueryKey = "";

  function close() {
    show(listEl, false);
    inputEl.setAttribute("aria-expanded", "false");
    activeIndex = -1;
    listEl.innerHTML = "";
  }

  function open() {
    show(listEl, true);
    inputEl.setAttribute("aria-expanded", "true");
  }

  function setActive(idx) {
    activeIndex = idx;
    const nodes = Array.from(listEl.querySelectorAll(".item"));
    nodes.forEach((n, i) => n.classList.toggle("active", i === activeIndex));
    if (activeIndex >= 0 && nodes[activeIndex]) nodes[activeIndex].scrollIntoView({ block: "nearest" });
  }

  function choose(idx) {
    const item = items[idx];
    if (!item) return;
    const r = renderItem(item);
    inputEl.value = r.value;
    close();
    if (typeof onSelectItem === "function") onSelectItem(item, r);
    else onSelectValue(r.value);
  }

  async function update(queryRaw) {
    const q = normalise(queryRaw);
    const qKey = q.toLowerCase();
    if (qKey === lastQueryKey) return;
    lastQueryKey = qKey;

    if (q.length < minChars) { close(); return; }

    const itemsRaw = await fetchItems(q);
    items = (itemsRaw || []).slice(0, maxItems);

    if (!items.length) { close(); return; }

    listEl.innerHTML = items.map((it, idx) => {
      const r = renderItem(it);
      const primary = escapeHtml(r.primary || "");
      const secondary = escapeHtml(r.secondary || "");
      const badge = escapeHtml(r.badge || "");
      return `
        <div class="item" role="option" data-idx="${idx}">
          <div class="left">
            <div class="primary">${primary}</div>
            ${secondary ? `<div class="secondaryText">${secondary}</div>` : ""}
          </div>
          ${badge ? `<div class="badge">${badge}</div>` : ""}
        </div>
      `;
    }).join("");

    Array.from(listEl.querySelectorAll(".item")).forEach(node => {
      node.addEventListener("mousedown", (e) => {
        e.preventDefault();
        const idx = Number(node.getAttribute("data-idx"));
        choose(idx);
      });
    });

    open();
    setActive(0);
  }

  const debouncedUpdate = debounce(update, 180);
  inputEl.addEventListener("input", () => debouncedUpdate(inputEl.value));
  inputEl.addEventListener("focus", () => {
    if (normalise(inputEl.value).length >= minChars) debouncedUpdate(inputEl.value);
  });
  inputEl.addEventListener("blur", () => setTimeout(close, 120));

  inputEl.addEventListener("keydown", (e) => {
    const isOpen = !listEl.classList.contains("hidden");
    if (!isOpen && (e.key === "ArrowDown" || e.key === "ArrowUp")) { debouncedUpdate(inputEl.value); return; }
    if (!isOpen) return;

    if (e.key === "ArrowDown") { e.preventDefault(); setActive(Math.min(activeIndex + 1, items.length - 1)); }
    else if (e.key === "ArrowUp") { e.preventDefault(); setActive(Math.max(activeIndex - 1, 0)); }
    else if (e.key === "Enter") { e.preventDefault(); if (activeIndex >= 0) choose(activeIndex); }
    else if (e.key === "Escape") { e.preventDefault(); close(); }
  });
}

async function searchClans(q) {
  const data = await fetchJson(`${API.clans}?q=${encodeURIComponent(q)}`);
  return Array.isArray(data) ? data : [];
}

async function searchPlayers(q, clanId = null) {
  const params = new URLSearchParams({ q });
  const configuredClan = String(clanId || getConfiguredClanId() || "").trim();
  if (configuredClan) params.set("clan", configuredClan);
  const data = await fetchJson(`${API.players}?${params.toString()}`);
  return Array.isArray(data) ? data : [];
}


function wireCharacterSearch(inputEl, listEl) {
  if (!inputEl || !listEl) return;

  createTypeahead({
    inputEl,
    listEl,
    fetchItems: (q) => searchPlayers(q, getConfiguredClanId()),
    renderItem: (p) => ({
      primary: p.rsn,
      secondary: p.clan ? `Clan: ${p.clan}` : "",
      badge: p.status ? p.status : "",
      value: p.rsn || "",
    }),
    onSelectValue: openPlayerProfile,
  });

  inputEl.addEventListener("keydown", (e) => {
    if (e.key !== "Enter" || e.defaultPrevented) return;
    const value = normalise(inputEl.value);
    if (!value) return;
    e.preventDefault();
    openPlayerProfile(value);
  });
}

function wireUI() {
  wireChartTooltips();

  const playerRsn = qs("playerRsn");

  wireCharacterSearch(qs("topPlayerRsn"), qs("topPlayerList"));
  wireCharacterSearch(qs("mobilePlayerRsn"), qs("mobilePlayerList"));

  if (playerRsn && qs("playerList")) {
    wireCharacterSearch(playerRsn, qs("playerList"));
  }

  qs("btnPlayer")?.addEventListener("click", () => {
    const v = normalise(playerRsn?.value);
    if (!v) {
      const notice = qs("notice");
      if (notice) notice.textContent = "Please enter a player RSN.";
      return;
    }
    openPlayerProfile(v);
  });

  qs("backFromClan")?.addEventListener("click", clearQuery);
  qs("backFromPlayer")?.addEventListener("click", clearQuery);
  qs("memberSearch")?.addEventListener("input", debounce(renderMemberList, 120));

  const rankSel = qs("rankFilter");
  if (rankSel) {
    rankSel.addEventListener("change", () => {
      const v = String(rankSel.value || "all");
      if (v === "all") setFilter("all");
      else setFilter(`rank:${v}`);
    });
  }

  document.querySelectorAll(".segBtn[data-filter]").forEach(btn => {
    btn.addEventListener("click", () => setFilter(btn.dataset.filter));
  });

  const clanXpTabs = qs("clanXpTabs");
  if (clanXpTabs) {
    clanXpTabs.querySelectorAll("button[data-xptab]").forEach(btn => {
      btn.addEventListener("click", () => setClanXpView(btn.getAttribute("data-xptab") || "total"));
    });
  }

  const clanXpSel = qs("clanXpPeriod");
  if (clanXpSel) {
    clanXpSel.addEventListener("change", () => {
      const v = clanXpSel.value || "7d";
      selectedClanXpPeriod = v;
      const { clan, configuredClan } = getParams();
      const clanToOpen = clan || configuredClan;
      if (clanToOpen) loadClanOverview(clanToOpen, selectedClanXpPeriod);
    });
  }

  qs("xpPeriod")?.addEventListener("change", () => {
    const v = qs("xpPeriod")?.value || "7d";
    selectedXpPeriod = v;
    const { player } = getParams();
    if (player) loadPlayer(player, selectedXpPeriod);
  });

  qs("activityLimit")?.addEventListener("change", () => {
    selectedActivityLimit = normaliseActivityLimit(qs("activityLimit")?.value || 20);
    const { player } = getParams();
    if (player) loadPlayer(player, selectedXpPeriod);
  });

  qs("journalTabs")?.querySelectorAll("[data-journal-view]").forEach(btn => {
    btn.addEventListener("click", () => setJournalView(btn.getAttribute("data-journal-view") || "activity"));
  });

  qs("skillViewToggle")?.querySelectorAll("button[data-skill-view]").forEach(btn => {
    btn.addEventListener("click", () => setSkillView(btn.getAttribute("data-skill-view") || "current"));
  });

  window.addEventListener("popstate", render);
}

wireUI();
render();
