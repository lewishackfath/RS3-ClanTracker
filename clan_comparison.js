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

  function skillSummary(skill) {
    if (!skill || typeof skill !== "object") return "—";
    const level = number(skill.display_level ?? skill.level);
    return `<span class="comparisonSkillSummary">${skillIcon(skill)} <strong>${escapeHtml(level)}</strong></span>`;
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
      const haystack = [
        member.rsn,
        member.rank_name,
        member.highest_skill?.skill,
        member.lowest_skill?.skill,
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

    setText("clanComparisonSubheading", `${data.clan?.name || "Clan"} active clan member overview sorted by rank.`);
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
              <th>Highest Skill</th>
              <th>Lowest Skill</th>
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
                  <td class="comparisonMemberCell"><a href="${escapeHtml(playerUrl(member.rsn))}" class="leaderLink">${escapeHtml(member.rsn)}</a> ${privatePill}</td>
                  <td><span class="comparisonRankCell">${icon}<span>${escapeHtml(member.rank_name || "—")}</span></span></td>
                  <td class="num">${number(member.total_level)}</td>
                  <td class="num">${number(member.total_xp)}</td>
                  <td>${skillSummary(member.highest_skill)}</td>
                  <td>${skillSummary(member.lowest_skill)}</td>
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
    if (status) status.textContent = "Loading clan comparison…";

    try {
      const params = new URLSearchParams();
      const clanId = configuredClanId();
      if (clanId) params.set("clan", clanId);

      const response = await fetch(`${API_URL}${params.toString() ? `?${params.toString()}` : ""}`, {
        headers: { "Accept": "application/json" },
      });

      const data = await response.json();
      if (!response.ok || !data.ok) {
        throw new Error(data.error || `Unable to load clan comparison (${response.status})`);
      }

      state.data = data;
      render();
    } catch (error) {
      if (status) status.textContent = error?.message || "Unable to load clan comparison.";
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
