# This daemon requires django, the python statsd library, and PSUtil: https://github.com/giampaolo/psutil
# The daemon reads system metrics and outputs them to StatsD
# Usage: python systemMetricsDaemon.py &

import time
from django.conf import settings
from django.core.management.base import BaseCommand
import psutil
from statsd import StatsClient
statsd = StatsClient()

last_disk_io  = psutil.disk_io_counters()
last_net_io   = psutil.net_io_counters()

time.sleep(1)

def io_change(last, current):
    return dict([(f, getattr(current, f) - getattr(last, f))
                 for f in last._fields])

while True:

    memory          = psutil.virtual_memory()
    disk            = psutil.disk_usage("/")
    disk_io         = psutil.disk_io_counters()
    disk_io_change  = io_change(last_disk_io, disk_io)
    net_io          = psutil.net_io_counters()
    net_io_change   = io_change(last_net_io, net_io)
    last_disk_io    = disk_io
    last_net_io     = net_io

    gauges = {
        "system.memory.used":        memory.used,
        "system.memory.free":        memory.free,
        "system.memory.percent":     memory.percent,
        "system.cpu.percent":        psutil.cpu_percent(),
        "system.load":               psutil.getloadavg()[0],
        "system.disk.size.used":     disk.used,
        "system.disk.size.free":     disk.free,
        "system.disk.size.percent":  disk.percent,
        "system.disk.read.bytes":    disk_io_change["read_bytes"],
        "system.disk.read.time":     disk_io_change["read_time"],
        "system.disk.write.bytes":   disk_io_change["write_bytes"],
        "system.disk.write.time":    disk_io_change["write_time"],
        "system.net.in.bytes":       net_io_change["bytes_recv"],
        "system.net.in.errors":      net_io_change["errin"],
        "system.net.in.dropped":     net_io_change["dropin"],
        "system.net.out.bytes":      net_io_change["bytes_sent"],
        "system.net.out.errors":     net_io_change["errout"],
        "system.net.out.dropped":    net_io_change["dropout"],
    }

    for name, value in gauges.items():
        statsd.gauge(name, value)

    time.sleep(10)
