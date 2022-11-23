# This daemon requires django, the python statsd library, and PSUtil: https://github.com/giampaolo/psutil
# The daemon reads system metrics and outputs them to StatsD
# Usage: python systemMetricsDaemon.py &

import time
from django.conf import settings
from django.core.management.base import BaseCommand
import psutil
from statsd import StatsClient
statsd = StatsClient()
sleep_seconds = 10

last_cpu        = psutil.cpu_times()
last_cpu_stats  = psutil.cpu_stats()
last_swap       = psutil.swap_memory()
last_disk_io    = psutil.disk_io_counters()
last_net_io     = psutil.net_io_counters()

time.sleep(sleep_seconds)

def io_change(last, current):
    return dict([(f, (getattr(current, f) - getattr(last, f)) / sleep_seconds)
                 for f in last._fields])

while True:

    cpu                 = psutil.cpu_times()
    cpu_change          = io_change(last_cpu, cpu)
    cpu_stats           = psutil.cpu_stats()
    cpu_stats_change    = io_change(last_cpu_stats, cpu_stats)
    memory              = psutil.virtual_memory()
    swap                = psutil.swap_memory()
    swap_change         = io_change(last_swap, swap)
    disk                = psutil.disk_usage("/")
    disk_io             = psutil.disk_io_counters()
    disk_io_change      = io_change(last_disk_io, disk_io)
    net_io              = psutil.net_io_counters()
    net_io_change       = io_change(last_net_io, net_io)
    uptime              = time.time() - psutil.boot_time()

    last_cpu            = cpu
    last_cpu_stats      = cpu_stats
    last_disk_io        = disk_io
    last_swap           = swap
    last_net_io         = net_io

    gauges = {
        "system.memory.used":               memory.used,
        "system.memory.free":               memory.free,
        "system.memory.percent":            memory.percent,
        "system.memory.available":          memory.available,
        "system.memory.active":             memory.active,
        "system.memory.inactive":           memory.inactive,
        "system.memory.cached":             memory.cached,
        "system.memory.buffers":            memory.buffers,
        "system.memory.shared":             memory.shared,
        "system.swap.used":                 swap.used,
        "system.swap.free":                 swap.free,
        "system.swap.percent":              swap.percent,
        "system.swap.sin":                  swap_change["sin"],
        "system.swap.sout":                 swap_change["sout"],
        "system.cpu.user":                  cpu_change["user"],
        "system.cpu.nice":                  cpu_change["nice"],
        "system.cpu.system":                cpu_change["system"],
        "system.cpu.idle":                  cpu_change["idle"],
        "system.cpu.iowait":                cpu_change["iowait"],
        "system.cpu.percent":               psutil.cpu_percent(),
        "system.cpu.context_switches":      cpu_stats_change["ctx_switches"],
        "system.cpu.interrupts":            cpu_stats_change["interrupts"],
        "system.cpu.soft_interrupts":       cpu_stats_change["soft_interrupts"],
        "system.cpu.syscalls":              cpu_stats_change["syscalls"],
        "system.load":                      psutil.getloadavg()[0],
        "system.disk.size.used":            disk.used,
        "system.disk.size.free":            disk.free,
        "system.disk.size.percent":         disk.percent,
        "system.disk.read.bytes":           disk_io_change["read_bytes"],
        "system.disk.read.count":           disk_io_change["read_count"],
        "system.disk.read.merged_count":    disk_io_change["read_merged_count"],
        "system.disk.read.time":            disk_io_change["read_time"],
        "system.disk.write.bytes":          disk_io_change["write_bytes"],
        "system.disk.write.count":          disk_io_change["write_count"],
        "system.disk.write.merged_count":   disk_io_change["write_merged_count"],
        "system.disk.write.time":           disk_io_change["write_time"],
        "system.disk.busy_time":            disk_io_change["busy_time"],
        "system.net.in.packets":            net_io_change["packets_recv"],
        "system.net.in.bytes":              net_io_change["bytes_recv"],
        "system.net.in.errors":             net_io_change["errin"],
        "system.net.in.dropped":            net_io_change["dropin"],
        "system.net.out.packets":           net_io_change["packets_sent"],
        "system.net.out.bytes":             net_io_change["bytes_sent"],
        "system.net.out.errors":            net_io_change["errout"],
        "system.net.out.dropped":           net_io_change["dropout"],
        "system.uptime":                    uptime,
    }

    for name, value in gauges.items():
        statsd.gauge(name, value)

    time.sleep(sleep_seconds)
