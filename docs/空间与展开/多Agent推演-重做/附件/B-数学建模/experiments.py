# -*- coding: utf-8 -*-
"""跑全部实验，结果写进 results.json。画图在 plots.py。"""
import json, sys, os, itertools
from multiprocessing import Pool
import numpy as np
sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
from org_economy import Params, mc, simulate, replace

HERE = os.path.dirname(os.path.abspath(__file__))
N_RUNS = 200
BASE = Params(T0=10000)          # 有关系起步：起步时已经有每月 1 万元的需求
COLD = Params(T0=0)              # 冷启动：起步时没有任何需求


def summ(p, n=N_RUNS):
    r = mc(p, n)
    return {k: v for k, v in r.items() if k != "runs"}


def job(args):
    key, p = args
    return key, summ(p)


def run_many(items, procs=4):
    with Pool(procs) as pool:
        return dict(pool.map(job, items))


def survival_curve(p, n=N_RUNS):
    T = p.T
    S = np.zeros(T)
    closed = np.zeros(T)
    for i in range(n):
        r = simulate(p, 1000 + i)
        d = r["dead_at"] if r["dead_at"] >= 0 else T
        S[:d] += 1
        if r["closed_at"] >= 0:
            closed[r["closed_at"]:] += 1
    return (S / n).tolist(), (closed / n).tolist()


def traj(p, n=N_RUNS):
    keys = ["N", "P", "cash_in", "need", "Q", "D", "s", "income", "gini", "rel_max", "freedom", "lam", "rho"]
    M = {k: np.full((n, p.T), np.nan) for k in keys}
    flows = {}
    for i in range(n):
        r = simulate(p, 1000 + i)
        for k in keys:
            h = r["hist"][k]
            M[k][i, :len(h)] = h
            if not r["alive"] and k == "N":
                M[k][i, len(h):] = 0
        if r["alive"]:
            for k, v in r["hist"].items():
                if k.startswith("f_") or k.startswith("t_") or k == "cash_in":
                    flows.setdefault(k, []).append(float(v.sum()))
    out = {}
    for k in keys:
        with np.errstate(all="ignore"):
            out[k] = {"med": np.nanmedian(M[k], 0).tolist(), "p25": np.nanpercentile(M[k], 25, 0).tolist(),
                      "p75": np.nanpercentile(M[k], 75, 0).tolist()}
    out["flows"] = {k: float(np.median(v)) for k, v in flows.items()}
    return out


if __name__ == "__main__":
    R = {}
    # ---------- E0 基线轨迹 ----------
    R["E0"] = {"base": traj(BASE), "cold": traj(COLD)}
    print("E0 done", flush=True)

    # ---------- E1 第一笔钱从哪来 ----------
    starts = {
        "只有存款（冷启动，全职）": COLD,
        "存款+外来 20 万": replace(COLD, seed=200000),
        "冷启动+公开工作 40%": replace(COLD, omega=0.4),
        "外面上班托底·不同领域": replace(COLD, outside=0.6, sigma=0.0),
        "外面上班托底·同领域": replace(COLD, outside=0.6, sigma=1.0),
        "已有关系（月需求 1 万）": BASE,
    }
    R["E1_curves"] = {k: survival_curve(p) for k, p in starts.items()}
    R["E1_summary"] = run_many([(k, p) for k, p in starts.items()])
    T0s = [0, 2500, 5000, 7500, 10000, 12500, 15000, 20000]
    items = []
    for T0 in T0s:
        items.append((f"full|{T0}", replace(COLD, T0=T0)))
        items.append((f"out0|{T0}", replace(COLD, T0=T0, outside=0.6, sigma=0.0)))
        items.append((f"out1|{T0}", replace(COLD, T0=T0, outside=0.6, sigma=1.0)))
    R["E1_T0"] = {"T0s": T0s, "res": run_many(items)}
    print("E1 done", flush=True)

    # ---------- E2 学习投入和早期盈利 ----------
    lams = [0.0, 0.05, 0.1, 0.15, 0.2, 0.25, 0.3, 0.35, 0.4, 0.5]
    dSs = [0.0, 0.005, 0.01, 0.015, 0.02, 0.03]
    items = [(f"{l}|{d}", replace(BASE, lam=l, dS=d)) for l in lams for d in dSs]
    R["E2_lam_dS"] = {"lams": lams, "dSs": dSs, "res": run_many(items)}
    T0s2 = [5000, 7500, 10000, 15000, 20000, 30000]
    items = [(f"{l}|{t}", replace(BASE, lam=l, T0=t)) for l in lams for t in T0s2]
    R["E2_lam_T0"] = {"lams": lams, "T0s": T0s2, "res": run_many(items)}
    pols = {
        "一直少学 λ=0.05": replace(BASE, lam=0.05),
        "一直 λ=0.2（每周1天）": BASE,
        "一直 λ=0.3+休闲0.1": replace(BASE, lam=0.3, rho=0.1),
        "先盈利 0.05→闭合后 0.2": replace(BASE, phase="phase", lam_e=0.05, lam_l=0.2, rho_l=0.0),
        "先盈利 0.05→0.3+0.1（18个月）": replace(BASE, phase="phase"),
        "先盈利 0.05→0.3+0.1（48个月）": replace(BASE, phase="phase", ramp=48),
        "有盈余才放宽 0.05→0.3+0.1": replace(BASE, phase="adaptive"),
        "有盈余才放宽 0.2→0.4+0.1": replace(BASE, phase="adaptive", lam_e=0.2, lam_l=0.4),
        "先学习 0.4→闭合后 0.2": replace(BASE, phase="phase", lam_e=0.4, lam_l=0.2, rho_l=0.0, ramp=6),
        "先学习 0.4→0.2，外来资助 10 万": replace(BASE, phase="phase", lam_e=0.4, lam_l=0.2, rho_l=0.0, ramp=6, seed=100000),
    }
    R["E2_policies"] = run_many(list(pols.items()))
    print("E2 done", flush=True)

    # ---------- E3 池子比例 ----------
    grid = []
    for a in range(11):
        for b in range(11 - a):
            for c in range(11 - a - b):
                d = 10 - a - b - c
                grid.append((a / 10, b / 10, c / 10, d / 10))
    items = [(f"{w}|{l}|{h}|{k}", replace(BASE, pW=w, pL=l, pH=h)) for (w, l, h, k) in grid]
    R["E3_pools"] = run_many(items)
    items = [(f"{m}", replace(BASE, m_R=m)) for m in [0, 1, 2, 3, 6, 9, 12]]
    R["E3_reserve"] = run_many(items)
    print("E3 done", flush=True)

    # ---------- E4 速度 ----------
    items = [(f"{d}|{a}", replace(BASE, delay=d, admit=a)) for d in [0, 1, 2, 3, 4, 6] for a in ["open", "demand", "reserve"]]
    R["E4_delay_admit"] = run_many(items)
    print("E4 done", flush=True)

    # ---------- E5 稳定 ----------
    items = [(f"{m}|{sp}", replace(BASE, m_R=m, shock_p=sp)) for m in [0, 1, 3, 6, 12] for sp in [0, 1 / 72, 1 / 36, 1 / 18, 1 / 9]]
    R["E5_reserve_shock"] = run_many(items)
    items = []
    for rot in [0.0, 0.05, 0.1, 0.2, 0.5, 1.0]:
        items.append((f"{rot}|none", replace(BASE, rot=rot)))
        items.append((f"{rot}|fexit24", replace(BASE, rot=rot, founder_exit_at=24)))
        items.append((f"{rot}|fexit24_take1", replace(BASE, rot=rot, founder_exit_at=24, exit_take=1.0)))
    R["E5_rotation"] = run_many(items)
    print("E5 done", flush=True)

    # ---------- E6 第二个主体怎么进来 ----------
    items = []
    for N0 in [1, 2, 3, 5]:
        for mu in [0.0, 0.01, 0.04, 0.1, 0.2]:
            for a in ["open", "demand", "reserve"]:
                items.append((f"{N0}|{mu}|{a}", replace(BASE, N0=N0, T0=10000 * N0 / 3, mu_conn=mu, admit=a)))
    R["E6_entry"] = run_many(items)
    R["E6_traj"] = {a: traj(replace(BASE, admit=a), 150) for a in ["open", "demand", "reserve"]}
    print("E6 done", flush=True)

    # ---------- E7 不能垄断 ----------
    combos = {f"{d}|{o}": replace(BASE, dist=d, own=o) for d in ["equal", "contrib"] for o in ["public", "accounts"]}
    R["E7_traj"] = {k: traj(p, 150) for k, p in combos.items()}
    R["E7_summary"] = run_many(list(combos.items()) + [(k + "|fexit24", replace(p, founder_exit_at=24)) for k, p in combos.items()])
    print("E7 done", flush=True)

    # ---------- E8 敏感性：每个〔AI〕参数 ×0.5 和 ×1.5 ----------
    names = ["W0", "contrib0", "W_new", "A", "s_founder", "s_new", "gL", "gW", "dS", "cL_max", "L_boost",
             "rot_cost", "c_coord", "aT", "dT", "a_pub", "sig_D", "shock_p", "shock_depth", "C_fix", "c_mem",
             "m_R", "mu_conn", "mu_vis", "pi0", "pi_found", "pi_broke", "pi_under", "free_keep", "exit_take", "F"]
    items = [("base", BASE)]
    for nm in names:
        v = getattr(BASE, nm)
        items.append((f"{nm}|lo", replace(BASE, **{nm: v * 0.5})))
        items.append((f"{nm}|hi", replace(BASE, **{nm: v * 1.5})))
    R["E8_sens"] = {"names": names, "res": run_many(items)}
    print("E8 done", flush=True)

    with open(os.path.join(HERE, "results.json"), "w", encoding="utf-8") as fh:
        json.dump(R, fh, ensure_ascii=False)
    print("saved")
