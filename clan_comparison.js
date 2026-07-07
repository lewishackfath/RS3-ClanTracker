(function () {
  const API_URL = "api/clan_comparison.php";
  const state = {
    data: null,
    search: "",
    rank: "all",
  };

  function el(id) { return document.getElementById(id); }

  function escapeHtml(value) {
    return String(value ?? "")
      .replaceAll("&", "&amp;")
      .replaceAll("<", "&lt;")
      .replaceAll(">", "&gt;")
      .replaceAll('"', "&quot;")
      .replaceAll("'", "&#039;");
  }

  function number(value) {
    if (value === null || value === undefined || value === "") return "—";
    const n = Number(value);
    return Number.isFinite(n) ? n.toLocaleString() : "—";
  }

  function setText(id, value) {
    const node = el(id);
    if (node) node.textContent = value;
  }

  function configuredClanId() {
    return String(window.TRACKER_CONFIG?.clanId || "").trim();
  }

  function playerUrl(rsn) {
    const url = new URL("index", window.location.href);
    url.searchParams.set("player", String(rsn || ""));
    return url.toString();
  }

  function skillIcon(skill) {
    const key = String(skill?.skill_key || skill?.skill || "").toLowerCase().replace(/[^a-z0-9]+/g, "");
    const name = String(skill?.skill || "Skill");
    return `<img class="miniIcon comparisonSkillIcon" src="assets/skills/${escapeHtml(key)}.png" alt="" loading="lazy" onerror="this.src='assets/skills/_default.png'" /> <span>${escapeHtml(name)}</span>`;
  }

  function cappedSkillDisplayLevel(skill) {
    if (!skill || typeof skill !== "object") return null;
    const name = String(skill.skill || skill.name || "Skill");
    const reported = Number(skill.level);
    const xp = Number(skill.xp);

    if (window.TrackerSkills && typeof window.TrackerSkills.getDisplayLevel === "function") {
      const result = window.TrackerSkills.getDisplayLevel(name, reported, xp);
      if (result && Number.isFinite(Number(result.displayLevel))) {
        const cap = typeof window.TrackerSkills.maxVirtualLevelForSkill === "function"
          ? Number(window.TrackerSkills.maxVirtualLevelForSkill(name))
          : null;
        const display = Number(result.displayLevel);
        return Number.isFinite(cap) && cap > 0 ? Math.min(cap, display) : display;
      }
    }

    const fallback = Number(skill.display_level ?? skill.level);
    if (!Number.isFinite(fallback)) return null;
    const cap = window.TrackerSkills && typeof window.TrackerSkills.maxVirtualLevelForSkill === "function"
      ? Number(window.TrackerSkills.maxVirtualLevelForSkill(name))
      : null;
    return Number.isFinite(cap) && cap > 0 ? Math.min(cap, fallback) : fallback;
  }

  function compactXp(value) {
    const n = Number(value);
    if (!Number.isFinite(n)) return "—";
    try {
      return `${new Intl.NumberFormat("en-AU", {
        notation: "compact",
        maximumFractionDigits: n >= 1_000_000 ? 1 : 0,
      }).format(n)} XP`;
    } catch (e) {
      return `${number(n)} XP`;
    }
  }

  function skillTieTitle(skills) {
    return skills.map(skill => {
      const name = String(skill?.skill || skill?.name || "Skill");
      const xp = Number(skill?.xp);
      const level = cappedSkillDisplayLevel(skill);
      const xpText = Number.isFinite(xp) ? `${number(xp)} XP` : "XP unknown";
      const levelText = Number.isFinite(Number(level)) ? `level ${level}` : "level unknown";
      return `${name}: ${xpText}, ${levelText}`;
    }).join("\n");
  }

  function normaliseSkillGroup(skills, fallback) {
    if (Array.isArray(skills) && skills.length) return skills.filter(skill => skill && typeof skill === "object");
    if (fallback && typeof fallback === "object") return [fallback];
    return [];
  }

  function skillGroupSummary(skills, fallback) {
    const list = normaliseSkillGroup(skills, fallback);
    if (!list.length) return "—";

    const primary = list[0];
    const level = cappedSkillDisplayLevel(primary);
    const levelText = Number.isFinite(Number(level)) ? `Level ${level}` : "Level —";
    const xp = Number(primary?.xp);
    const xpText = Number.isFinite(xp) ? compactXp(xp) : "XP —";
    const more = list.length > 1
      ? `<span class="comparisonSkillMore" title="${escapeHtml(skillTieTitle(list))}">+${escapeHtml(String(list.length - 1))} more</span>`
      : "";

    return `<span class="comparisonSkillSummary${list.length > 1 ? " hasTie" : ""}" title="${escapeHtml(skillTieTitle(list))}">${skillIcon(primary)} <span class="comparisonSkillMetrics"><strong>${escapeHtml(xpText)}</strong><small>${escapeHtml(levelText)}</small></span>${more}</span>`;
  }

  function renderRankFilter() {
    const select = el("comparisonRankFilter");
    const data = state.data;
    if (!select || !data || !Array.isArray(data.ranks)) return;
    const current = state.rank;
    select.innerHTML = '<option value="all">All ranks</option>' + data.ranks.map(rank => {
      const selected = rank === current ? " selected" : "";
      return `<option value="${escapeHtml(rank)}"${selected}>${escapeHtml(rank)}</option>`;
    }).join("");
  }

  function filteredMembers() {
    const data = state.data;
    if (!data || !Array.isArray(data.members)) return [];
    const needle = state.search.trim().toLowerCase();
    const wantedRank = state.rank;

    return data.members.filter(member => {
      if (wantedRank !== "all" && String(member.rank_name || "") !== wantedRank) return false;
      if (!needle) return true;
      const highestSkillNames = normaliseSkillGroup(member.highest_skills, member.highest_skill).map(skill => skill?.skill || skill?.name);
      const lowestSkillNames = normaliseSkillGroup(member.lowest_skills, member.lowest_skill).map(skill => skill?.skill || skill?.name);
      const haystack = [
        member.rsn,
        member.rank_name,
        ...highestSkillNames,
        ...lowestSkillNames,
      ].map(v => String(v || "").toLowerCase()).join(" ");
      return haystack.includes(needle);
    });
  }

  function render() {
    const data = state.data;
    const wrap = el("comparisonTableWrap");
    if (!wrap) return;

    if (!data) {
      wrap.innerHTML = "";
      return;
    }

    setText("clanComparisonSubheading", `${data.clan?.name || "Clan"} active member overview sorted by rank.`);
    setText("comparisonActiveMembers", number(data.stats?.active_members));
    setText("comparisonPrivateProfiles", number(data.stats?.private_profiles));
    setText("comparisonGenerated", data.generated_at_utc ? `Generated: ${data.generated_at_utc} UTC` : "");

    renderRankFilter();

    const members = filteredMembers();
    setText("comparisonStatus", `${number(members.length)} members shown`);

    if (!members.length) {
      wrap.innerHTML = '<div class="panel"><div class="muted">No active members matched this filter.</div></div>';
      return;
    }

    wrap.innerHTML = `
      <div class="comparisonTableScroll">
        <table class="comparisonTable">
          <thead>
            <tr>
              <th>Member</th>
              <th>Rank</th>
              <th class="num">Total Level</th>
              <th class="num">Total XP</th>
              <th>Highest XP Skill</th>
              <th>Lowest XP Skill</th>
              <th class="num">RuneScore</th>
              <th class="num">Quest Points</th>
              <th class="num">Clues</th>
            </tr>
          </thead>
          <tbody>
            ${members.map(member => {
              const icon = member.rank_icon
                ? `<img class="comparisonRankIcon" src="${escapeHtml(member.rank_icon)}" alt="" loading="lazy" onerror="this.remove()" />`
                : "";
              const privatePill = member.is_private ? '<span class="pill private">Private</span>' : "";
              return `
                <tr>
                  <td class="comparisonMemberCell"><a href="${escapeHtml(playerUrl(member.rsn))}" class="leaderLink comparisonMemberLink">${escapeHtml(member.rsn)}</a> ${privatePill}</td>
                  <td><span class="comparisonRankCell">${icon}<span>${escapeHtml(member.rank_name || "—")}</span></span></td>
                  <td class="num">${number(member.total_level)}</td>
                  <td class="num">${number(member.total_xp)}</td>
                  <td>${skillGroupSummary(member.highest_skills, member.highest_skill)}</td>
                  <td>${skillGroupSummary(member.lowest_skills, member.lowest_skill)}</td>
                  <td class="num">${number(member.runescore)}</td>
                  <td class="num">${number(member.quest_points)}</td>
                  <td class="num">${number(member.clue_total)}</td>
                </tr>
              `;
            }).join("")}
          </tbody>
        </table>
      </div>
    `;
  }

  async function loadComparison() {
    const status = el("comparisonStatus");
    if (status) status.textContent = "Loading member comparison…";

    try {
      const params = new URLSearchParams();
      const clanId = configuredClanId();
      if (clanId) params.set("clan", clanId);

      const response = await fetch(`${API_URL}${params.toString() ? `?${params.toString()}` : ""}`, {
        headers: { "Accept": "application/json" },
      });

      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || `Unable to load member comparison (${response.status})`);
      }

      state.data = data;
      render();
    } catch (error) {
      if (status) status.textContent = error?.message || "Unable to load member comparison.";
      const wrap = el("comparisonTableWrap");
      if (wrap) wrap.innerHTML = "";
    }
  }

  function wireComparison() {
    if (!el("clanComparisonPage")) return;

    el("comparisonSearch")?.addEventListener("input", event => {
      state.search = String(event.target.value || "");
      render();
    });

    el("comparisonRankFilter")?.addEventListener("change", event => {
      state.rank = String(event.target.value || "all");
      render();
    });

    loadComparison();
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", wireComparison);
  } else {
    wireComparison();
  }
})();
