#!/usr/bin/env python3
"""Kurage 学童保育ナビ PV 用の H3 実写クリップ（8秒）を rqdb4ai の h3 キューに投入する。
  /usr/bin/python3 enqueue_h3.py → job id を h3_job_ids.txt に保存。
  完成物は /home/kojima/work/kpvgen/outputs/h3_queue/h3-kgakudo-8s.mp4（所要 約28分）
**子どもの顔は写さない構図にする**（放課後の教室・ランドセル・夕方の光）。
"""
import copy, json, os
from redis import Redis
from rq import Queue

HERE = os.path.dirname(os.path.abspath(__file__))
TPL = "/home/kojima/work/kpvgen/h3_workflow_template.json"
SEED = 20260921
base = json.load(open(TPL))
q = Queue("h3-192-168-0-14", connection=Redis.from_url("redis://127.0.0.1:6379/0"))
wf = copy.deepcopy(base)
wf["6"]["inputs"]["prompt"] = open(os.path.join(HERE, "h3_kgakudo_prompt.txt"), encoding="utf-8").read().strip()
wf["10"]["inputs"]["noise_seed"] = SEED
wf["15"]["inputs"]["filename_prefix"] = "h3_kgakudo"
json.dump(wf, open(os.path.join(HERE, "h3_kgakudo_workflow.json"), "w"), ensure_ascii=False, indent=1)
job = q.enqueue("rqdb4ai_h3_job.h3_generate_job", workflow=wf,
                output_filename="h3-kgakudo-8s.mp4", job_timeout=5400,
                result_ttl=86400, failure_ttl=86400)
open(os.path.join(HERE, "h3_job_ids.txt"), "w").write(job.id + "\n")
print("enqueued", job.id, "/ queue count:", q.count)
