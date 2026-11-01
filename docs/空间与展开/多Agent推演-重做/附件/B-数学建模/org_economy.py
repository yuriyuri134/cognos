# -*- coding: utf-8 -*-
"""
一个组织的经济系统：可运行的模型（多 Agent 推演重做，第 1 轮，Agent B）

这是一个构造出来的实验对象，不是真相。
每个参数后面标了来源：
  〔原 日期〕 小林原话给的结构或数字
  〔世〕     现实材料
  〔AI〕     AI 的假设（实验里会去改它，看结果怎么变）

时间单位：月。钱的单位：元。

模型里的东西：
  成员（多个主体）：技能 s、个人存款 w、个人资本账户 acct、手上的关系 rel、是否创始人、外部工作占比 o
  组织：公共池 P（现金，公开）、学习池 LF、享乐休闲池 HF、应收款队列、信任 T（外面愿意给这个组织的月需求，元/月）
一个月里发生的事：
  1. 每个成员的时间分成：外部工作 o，组织内的学习 λ、休闲 ρ、公开的工作 ω、沟通调度 φ(N)、劳动
  2. 劳动产出 Q = Σ A·s·劳动·(1-轮转成本)
  3. 卖出 = min(Q, 需求)，需求 = 信任 T × 随机波动 × 冲击
  4. 卖出的钱 delay 个月以后进公共池
  5. 公共池先付每人底线 F 和固定成本；付不起就按比例付，成员动用自己的存款
  6. 公共池高于储备线时，本月盈余按四个池分：劳动池(发给成员) / 学习池 / 享乐休闲池 / 留在公共池
  7. 技能：学习、干中学会涨，不学会折旧
  8. 信任：交付出去的东西带来口碑，公开的工作带来看见，会自然衰减
  9. 有人离开（存款见底、底线付不起、基础流动），离开的人带走一部分他手上的关系
 10. 新的人从成员的关系和组织的可见度里来，组织按进人规则决定收不收
"""
from dataclasses import dataclass, replace, asdict
import numpy as np


@dataclass
class Params:
    T: int = 72                  # 模拟 72 个月（6 年）〔AI〕
    # ---------- 人与底线 ----------
    N0: int = 3                  # 创始人数〔AI〕；6/27「开始的时候……能一起承担起责任」〔原 2026-06-27〕
    F: float = 4000.0            # 每人每月底线〔原 10/24 07:01「每月至少要 4000 才不慌」，是小林自己的数，借来给所有成员〕
    W0: float = 24000.0          # 每个创始人起步时的个人存款（6 个月底线）〔AI〕
    contrib0: float = 0.5        # 创始人把一半存款放进公共池〔AI〕
    seed: float = 0.0            # 外来的起步钱（借款、资助）〔AI〕
    W_new: float = 8000.0        # 新成员自带的存款（2 个月底线）〔AI〕
    # ---------- 生产与技能 ----------
    A: float = 10000.0           # 技能=1 的人全月劳动能产出的价值（元/月），前提是有人要〔AI〕
    s_founder: float = 0.6       # 创始人起步技能〔AI〕
    s_new: float = 0.5           # 新成员起步技能〔AI〕
    s_max: float = 2.0           # 技能上限〔AI〕
    gL: float = 0.25             # 学习效率：全部时间学习时每月技能增量（乘 (1-s/s_max)）〔AI〕
    gW: float = 0.02             # 干中学〔AI〕
    dS: float = 0.015            # 技能折旧/月。AI、软件领域变得快，取 1.5%/月（约 17%/年）〔AI〕
    cL_max: float = 1000.0       # 学习池每人每月最多花多少〔AI〕
    L_boost: float = 0.5         # 学习池花满时学习效率 +50%〔AI〕
    # ---------- 时间分配（政策）----------
    lam: float = 0.20            # 学习占比。〔原 5/3〕「学习培训占比要比以往所有企业都要多」；0.2 = 每周 1 天〔AI 转述 纸上模拟第九节表〕
    rho: float = 0.0             # 组织给的额外休闲时间占比〔原 6/27「应该还加上享乐、休闲池」，数值 AI〕
    omega: float = 0.10          # 公开的工作占比（开源、写出来、让人看见）〔原 6/27「公共、公开是很重要的一环」，数值 AI〕
    rot: float = 0.20            # 轮转强度：每月有多大比例的「手上的东西」摊给大家〔原 5/3「人员可轮转制」；6/27 连接调度部分「人员是自由轮转的」；数值 AI〕
    rot_cost: float = 0.25       # 轮转的切换成本：rot=1 时产出 -25%〔AI〕
    c_coord: float = 0.03        # 沟通调度占时 = c_coord·log2(N)〔AI〕；6/27「连接调度部分」
    phase: str = "const"         # const：一直用 lam/rho；phase：早期 lam_e/rho_e，闭合后按 ramp 个月爬到 lam_l/rho_l；
                                 # adaptive：从 lam_e/rho_e 起，有盈余就每月放宽 f_up，亏了就收回 f_down，上限 lam_l/rho_l
                                 # 〔原 5/3「早期先抓紧盈利能力。后期一步步拓展，变得自由一些」〕
    f_up: float = 0.005          # 〔AI〕
    f_down: float = 0.01         # 〔AI〕
    lam_e: float = 0.05
    rho_e: float = 0.0
    lam_l: float = 0.30
    rho_l: float = 0.10
    ramp: int = 18               # 闭合后用 18 个月一步步拓展〔AI〕
    # ---------- 外部托底 ----------
    outside: float = 0.0         # 创始人在外面上班占的时间（0.6 = 白天上班，晚上和周末做组织）〔AI〕
    W_out: float = 4000.0        # 外部工作的月收入〔原 10/24 08:51 CNC 学徒工资能到每月 4000〕
    sigma: float = 0.0           # 外部工作和组织是不是同一个领域：1=同领域（技能和关系能带回来），0=不同领域〔AI；上次实例检验者第 2 格〕
    # ---------- 需求与信任 ----------
    T0: float = 0.0              # 起步时已经在流的关系能带来的月需求（元/月）〔AI；上次实例检验者第 1 格〕
    aT: float = 0.06             # 每交付 1 元，月需求多 0.06 元（口碑）〔AI〕
    dT: float = 0.03             # 信任每月衰减 3%〔AI〕
    a_pub: float = 1500.0        # 一个技能=1 的人全月做公开的工作，月需求多 1500 元（被看见）〔AI〕
    M_cap: float = 3e6           # 能够到的市场上限（元/月）〔AI〕
    sig_D: float = 0.15          # 需求月波动〔AI〕
    shock_p: float = 1 / 36      # 平均三年一次需求冲击〔AI〕
    shock_depth: float = 0.4     # 冲击时需求降到 40%〔AI〕
    shock_len: int = 3           # 持续 3 个月〔AI〕
    # ---------- 速度 ----------
    delay: int = 1               # 交付到收钱隔几个月〔AI〕；〔原 5/3「速度」〕
    # ---------- 成本 ----------
    C_fix: float = 1000.0        # 每月固定成本（工具、算力）〔AI〕
    c_mem: float = 300.0         # 每人每月的工具成本〔AI〕
    # ---------- 分配 ----------
    m_R: float = 3.0             # 储备线：公共池要先存够 3 个月开销才开始分〔AI〕
    pW: float = 0.40             # 劳动池（发给成员）〔原 6/27「劳动池」，数值 AI〕
    pL: float = 0.15             # 学习池〔原 6/27「大力投入组织人员学习」，数值 AI〕
    pH: float = 0.15             # 享乐休闲池〔原 6/27「应该还加上享乐、休闲池」，数值 AI〕
    #       剩下 1-pW-pL-pH 留在公共池〔原 6/27「公共、公开」；上次实例检验者第 4 格〕
    cH_max: float = 1000.0       # 享乐休闲池每人每月最多花多少〔AI〕
    dist: str = "equal"          # 劳动池怎么分：equal 平均〔原 2025-01「所有人薪酬尽量平等」〕；contrib 按产出
    own: str = "public"          # 留下的钱归谁：public 不属于任何个人；accounts 记进个人资本账户，离开时带走〔世 Mondragon 70/30，见上次实例检验者〕
    acct_share: float = 0.7      # accounts 模式下，留下的钱有 70% 记进个人账户〔世 Mondragon〕
    # ---------- 进人 ----------
    admit: str = "demand"        # open：付得起一个月就收；reserve：储备线之上还够新人 k_new 个月才收；demand：有接不住的需求才收〔AI〕
    k_new: float = 6.0           # 〔AI〕
    mu_conn: float = 0.04        # 每个成员每月带来 0.04 个候选人（人先到）〔AI〕
    mu_vis: float = 0.5          # 月需求每 10 万元，每月带来 0.5 个候选人（被看见）〔AI〕
    # ---------- 离开 ----------
    pi0: float = 0.010           # 普通成员基础离开率/月〔AI〕
    pi_found: float = 0.004      # 创始人基础离开率/月〔AI〕
    pi_broke: float = 0.30       # 存款见底时每月离开概率〔AI〕
    pi_under: float = 0.05       # 本月底线没付足时多加的离开概率〔AI〕
    free_keep: float = 0.6       # 自由（学习+休闲）到 0.4 时，基础离开率降 60%〔AI〕
    exit_take: float = 0.7       # 一个人走，他手上 70% 的关系跟着走〔AI〕
    N_cap: int = 80              # 计算上限〔AI〕
    # ---------- 外加冲击（实验用）----------
    founder_exit_at: int = -1    # 在第几个月让一个创始人离开（-1 不加）


def gini(x):
    x = np.clip(np.asarray(x, dtype=float), 0, None)
    if x.size == 0 or x.sum() <= 0:
        return 0.0
    x = np.sort(x)
    n = x.size
    return float((2 * np.arange(1, n + 1) - n - 1).dot(x) / (n * x.sum()))


def simulate(p: Params, seed: int = 0, record: bool = True):
    rng = np.random.default_rng(seed)
    n = p.N0
    s = np.full(n, p.s_founder)
    w = np.full(n, p.W0 * (1 - p.contrib0))
    acct = np.zeros(n)
    rel = np.full(n, p.T0 / max(n, 1))       # 起步关系平均在创始人手上
    founder = np.ones(n, bool)
    o = np.full(n, p.outside)                 # 外部工作占比
    P = p.W0 * p.contrib0 * n + p.seed        # 公共池
    LF = 0.0
    HF = 0.0
    Tr = p.T0
    rec = np.zeros(p.T + 10)                  # 应收款
    shock_left = 0
    closed_at = -1
    close_streak = 0
    under_streak = 0
    dead_at = -1
    last_shock = -100
    lam_t, rho_t = (p.lam, p.rho) if p.phase == "const" else (p.lam_e, p.rho_e)
    f0 = p.lam_e + p.rho_e
    f1 = p.lam_l + p.rho_l
    share_l = (p.lam_l - p.lam_e) / max(f1 - f0, 1e-9)
    f = f0
    hist = {k: [] for k in ["N", "P", "cash_in", "need", "Q", "D", "sold", "Tr", "s", "lam", "rho",
                            "income", "gini", "founder_share", "rel_max", "bonus", "freedom", "under",
                            "f_floor", "f_cost", "f_bonus", "f_learn", "f_leis", "f_keep",
                            "t_labor", "t_learn", "t_leis", "t_pub", "t_coord", "t_rot", "t_out"]}
    joined_total = 0
    left_total = 0
    shocks = 0
    for t in range(p.T):
        N = s.size
        if N == 0:
            dead_at = t if dead_at < 0 else dead_at
            break
        # ---- 政策：早期先抓盈利，后期一步步拓展 ----
        if p.phase == "phase":
            if closed_at >= 0:
                k = min(1.0, (t - closed_at) / max(p.ramp, 1))
                lam_t = p.lam_e + k * (p.lam_l - p.lam_e)
                rho_t = p.rho_e + k * (p.rho_l - p.rho_e)
            else:
                lam_t, rho_t = p.lam_e, p.rho_e
        if p.phase == "adaptive":
            lam_t = p.lam_e + share_l * (f - f0)
            rho_t = p.rho_e + (1 - share_l) * (f - f0)
        phi = p.c_coord * np.log2(N) if N > 1 else 0.0
        org = 1 - o
        labor = np.clip(org * (1 - lam_t - rho_t - p.omega - phi), 0, None)
        q = p.A * s * labor * (1 - p.rot_cost * p.rot)
        Q = q.sum()
        # ---- 需求 ----
        if shock_left == 0 and rng.random() < p.shock_p:
            shock_left = p.shock_len
            last_shock = t
            shocks += 1
        sf = p.shock_depth if shock_left > 0 else 1.0
        shock_left = max(0, shock_left - 1)
        eps = rng.lognormal(-0.5 * p.sig_D ** 2, p.sig_D)
        D = Tr * eps * sf
        sold = min(Q, D)
        sold_i = q * (sold / Q) if Q > 0 else np.zeros(N)
        rec[t + p.delay] += sold
        # ---- 信任 ----
        pub = p.a_pub * (p.omega * org * s).sum()
        out_tr = p.sigma * p.aT * 0.5 * p.A * (s * o).sum()
        Tr = Tr * (1 - p.dT) + p.aT * sold * max(0.0, 1 - Tr / p.M_cap) + pub + out_tr
        # 每个人手上的关系：跟信任同样的来路、同样衰减
        rel = rel * (1 - p.dT) + p.aT * sold_i + p.a_pub * p.omega * org * s + p.sigma * p.aT * 0.5 * p.A * s * o
        rel = (1 - p.rot) * rel + p.rot * rel.mean()
        # ---- 现金：收款、付底线、付成本 ----
        cash_in = rec[t]
        P += cash_in
        full_org = (o == 0)
        need_floor = p.F * full_org.sum()
        costs = p.C_fix + p.c_mem * N
        need = need_floor + costs
        if P >= need:
            pay_frac = 1.0
            P -= need
        else:
            pay_frac = max(P - costs, 0) / need_floor if need_floor > 0 else 1.0
            pay_frac = min(max(pay_frac, 0.0), 1.0)
            P = max(P - costs - pay_frac * need_floor, 0.0)
        paid = np.where(full_org, p.F * pay_frac, 0.0)
        income = paid + np.where(o > 0, p.W_out, 0.0)
        under = pay_frac < 0.999 and need_floor > 0
        under_streak = under_streak + 1 if under else 0
        # ---- 闭合：连续 3 个月收进来的钱够付所有成员的底线（包括还在外面上班的创始人）和成本 ----
        need_full = p.F * N + costs
        if cash_in >= need_full and N > 0:
            close_streak += 1
        else:
            close_streak = 0
        if closed_at < 0 and close_streak >= 3:
            closed_at = t
        # ---- 盈余分配（四个池）----
        net = cash_in - need
        R_star = p.m_R * need
        if p.phase == "adaptive":
            if net > 0.15 * need and P > R_star:
                f = min(f1, f + p.f_up)
            elif net < 0:
                f = max(f0, f - p.f_down)
        bonus = np.zeros(N)
        S = 0.0
        if net > 0 and P > R_star:
            S = min(net, P - R_star)
            P -= S * (p.pW + p.pL + p.pH)
            if p.dist == "equal":
                bonus = np.full(N, p.pW * S / N)
            else:
                wts = sold_i + 1e-9
                bonus = p.pW * S * wts / wts.sum()
            LF += p.pL * S
            HF += p.pH * S
            if p.own == "accounts":
                wts = sold_i + 1e-9
                acct += p.acct_share * (1 - p.pW - p.pL - p.pH) * S * wts / wts.sum()
        income = income + bonus
        w = w + income - p.F
        spendL = min(LF, N * p.cL_max)
        LF -= spendL
        boost = 1 + p.L_boost * spendL / (N * p.cL_max)
        spendH = min(HF, N * p.cH_max)
        HF -= spendH
        h_money = spendH / N
        # ---- 技能 ----
        room = 1 - s / p.s_max
        s = s + p.gL * lam_t * org * boost * room + p.gW * labor * room + p.sigma * p.gW * o * room - p.dS * s
        s = np.clip(s, 0.05, p.s_max)
        # ---- 记录 ----
        freedom = (lam_t + rho_t) * org.mean()
        if record:
            tot = np.clip(w, 0, None) + acct
            fs = tot[founder].sum() / tot.sum() if tot.sum() > 0 and founder.any() else 0.0
            hist["N"].append(N); hist["P"].append(P); hist["cash_in"].append(cash_in); hist["need"].append(need)
            hist["Q"].append(Q); hist["D"].append(D); hist["sold"].append(sold); hist["Tr"].append(Tr)
            hist["s"].append(s.mean()); hist["lam"].append(lam_t); hist["rho"].append(rho_t)
            hist["income"].append(income.mean()); hist["gini"].append(gini(tot)); hist["founder_share"].append(fs)
            hist["rel_max"].append(rel.max() / rel.sum() if rel.sum() > 0 else 0.0)
            hist["bonus"].append(bonus.mean()); hist["freedom"].append(freedom + h_money / p.F); hist["under"].append(float(under))
            hist["f_floor"].append(paid.sum()); hist["f_cost"].append(min(costs, cash_in + 1e12)); hist["f_bonus"].append(bonus.sum())
            hist["f_learn"].append(p.pL * S); hist["f_leis"].append(p.pH * S); hist["f_keep"].append((1 - p.pW - p.pL - p.pH) * S)
            hist["t_labor"].append((labor * (1 - p.rot_cost * p.rot)).sum()); hist["t_learn"].append((lam_t * org).sum())
            hist["t_leis"].append((rho_t * org).sum()); hist["t_pub"].append((p.omega * org).sum())
            hist["t_coord"].append((phi * org).sum()); hist["t_rot"].append((labor * p.rot_cost * p.rot).sum()); hist["t_out"].append(o.sum())
        # ---- 离开 ----
        free_i = (lam_t + rho_t) * org + h_money / p.F
        base = np.where(founder, p.pi_found, p.pi0) * (1 - p.free_keep * np.minimum(1, free_i / 0.4))
        pi = base + p.pi_broke * (w < 0) + p.pi_under * under
        leave = rng.random(N) < np.clip(pi, 0, 1)
        if t == p.founder_exit_at and founder.any():
            leave[np.argmax(np.where(founder, rel, -1))] = True
        if leave.any():
            for i in np.where(leave)[0]:
                if rel.sum() > 0:
                    Tr *= (1 - p.exit_take * rel[i] / rel.sum())
                if p.own == "accounts":
                    take = min(acct[i], P)
                    P -= take
            keep = ~leave
            left_total += int(leave.sum())
            s, w, acct, rel, founder, o = s[keep], w[keep], acct[keep], rel[keep], founder[keep], o[keep]
        N = s.size
        if N == 0:
            dead_at = t
            break
        # ---- 创始人辞掉外部工作，全职进组织 ----
        if (o > 0).any() and closed_at >= 0:
            R_next = p.m_R * (p.F * ((o == 0).sum() + 1) + costs)
            if P > R_next + 3 * p.F:
                j = np.where(o > 0)[0][0]
                o[j] = 0.0
        # ---- 新的人进来 ----
        lam_rate = p.mu_conn * N + p.mu_vis * Tr / 1e5
        n_cand = rng.poisson(lam_rate)
        accept_p = np.clip(0.2 + 0.5 * min(1, (lam_t + rho_t + h_money / p.F) / 0.4) + 0.3 * min(1, bonus.mean() / p.F), 0, 1)
        for _ in range(n_cand):
            if s.size >= p.N_cap:
                break
            if rng.random() > accept_p:
                continue
            N_now = s.size
            need_now = p.F * ((o == 0).sum()) + p.C_fix + p.c_mem * N_now
            if p.admit == "open":
                ok = P >= p.F + p.c_mem
            elif p.admit == "reserve":
                ok = P >= p.m_R * need_now + p.k_new * p.F
            else:  # demand
                spare = D - Q
                ok = spare > p.A * p.s_new * 0.6 and P >= 2 * p.F
                D -= p.A * p.s_new * 0.6
            if not ok:
                continue
            s = np.append(s, p.s_new); w = np.append(w, p.W_new); acct = np.append(acct, 0.0)
            rel = np.append(rel, 0.0); founder = np.append(founder, False); o = np.append(o, 0.0)
            joined_total += 1
        # ---- 现金断：连续 6 个月付不起底线、公共池见底 ----
        if under_streak >= 6 and P < p.F:
            dead_at = t
            break
    alive = dead_at < 0
    if not alive:
        if closed_at < 0:
            cause = "起步断"
        elif dead_at - last_shock <= 9:
            cause = "冲击断"
        else:
            cause = "人走断"
    else:
        cause = "活着"
    out = {
        "alive": alive, "dead_at": dead_at, "closed_at": closed_at, "cause": cause,
        "N_end": int(s.size) if alive else 0, "P_end": P if alive else 0.0,
        "joined": joined_total, "left": left_total, "shocks": shocks,
    }
    if record:
        out["hist"] = {k: np.array(v) for k, v in hist.items()}
    return out


def mc(p: Params, n: int = 300, seed0: int = 1000, keep_hist: bool = False):
    """跑 n 次，返回汇总。"""
    runs = [simulate(p, seed0 + i, record=True) for i in range(n)]
    alive = np.array([r["alive"] for r in runs])
    closed = np.array([r["closed_at"] for r in runs])
    Nend = np.array([r["N_end"] for r in runs])
    causes = {}
    for r in runs:
        causes[r["cause"]] = causes.get(r["cause"], 0) + 1
    # 只看活下来的
    def tail_mean(key, k=12):
        vals = [r["hist"][key][-k:].mean() for r in runs if r["alive"] and len(r["hist"][key]) >= k]
        return float(np.median(vals)) if vals else float("nan")
    fr = [r["hist"]["freedom"].mean() for r in runs if r["alive"] and len(r["hist"]["freedom"])]
    grow = np.array([r["alive"] and r["closed_at"] >= 0 and r["N_end"] > p.N0 for r in runs])
    vel = []
    for r in runs:
        if r["alive"] and len(r["hist"]["P"]) >= 12:
            vel.append(r["hist"]["cash_in"][-12:].sum() / max(r["hist"]["P"][-12:].mean(), 1.0))
    res = {
        "surv": float(alive.mean()),
        "grow": float(grow.mean()),
        "velocity": float(np.median(vel)) if vel else float("nan"),
        "p_closed": float((closed >= 0).mean()),
        "close_med": float(np.median(closed[closed >= 0])) if (closed >= 0).any() else float("nan"),
        "N_med": float(np.median(Nend)),
        "N_med_alive": float(np.median(Nend[alive])) if alive.any() else 0.0,
        "N_p10": float(np.percentile(Nend, 10)), "N_p90": float(np.percentile(Nend, 90)),
        "income_last12": tail_mean("income"),
        "gini_last12": tail_mean("gini"),
        "fshare_last12": tail_mean("founder_share"),
        "relmax_last12": tail_mean("rel_max"),
        "freedom": float(np.median(fr)) if fr else float("nan"),
        "causes": causes,
    }
    if keep_hist:
        res["runs"] = runs
    return res


def band(runs, key, T, fill_dead=0.0):
    """每个月的中位数和 25/75 分位；死掉的 run 之后按 fill_dead 计。"""
    M = np.full((len(runs), T), np.nan if fill_dead is None else fill_dead, float)
    for i, r in enumerate(runs):
        h = r["hist"][key]
        M[i, :len(h)] = h
    return np.nanmedian(M, 0), np.nanpercentile(M, 25, 0), np.nanpercentile(M, 75, 0)
