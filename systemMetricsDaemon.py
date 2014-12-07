# This daemon requires django, the python statsd library, and PSUtil: https://github.com/giampaolo/psutil
# The daemon reads system metrics and outputs them to StatsD
# Usage: python systemMetricsDaemon.py &

import os
import time

from django.conf import settings
from django.core.management.base import NoArgsCommand
import psutil
import statsd

last_disk_io  = psutil.disk_io_counters()
last_net_io   = psutil.net_io_counters()
gauge = statsd.Gauge('system')
time.sleep(1)

def io_change(last, current):
    return dict([(f, getattr(current, f) - getattr(last, f))
                 for f in last._fields])

while True:

    memory          = psutil.phymem_usage()
    disk            = psutil.disk_usage("/")
    disk_io         = psutil.disk_io_counters()
    disk_io_change  = io_change(last_disk_io, disk_io)
    net_io          = psutil.net_io_counters()
    net_io_change   = io_change(last_net_io, net_io)
    last_disk_io    = disk_io
    last_net_io     = net_io

    gauges = {
        "memory.used":        memory.used,
        "memory.free":        memory.free,
        "memory.percent":     memory.percent,
        "cpu.percent":        psutil.cpu_percent(),
        "load":               os.getloadavg()[0],
        "disk.size.used":     disk.used,
        "disk.size.free":     disk.free,
        "disk.size.percent":  disk.percent,
        "disk.read.bytes":    disk_io_change["read_bytes"],
        "disk.read.time":     disk_io_change["read_time"],
        "disk.write.bytes":   disk_io_change["write_bytes"],
        "disk.write.time":    disk_io_change["write_time"],
        "net.in.bytes":       net_io_change["bytes_recv"],
        "net.in.errors":      net_io_change["errin"],
        "net.in.dropped":     net_io_change["dropin"],
        "net.out.bytes":      net_io_change["bytes_sent"],
        "net.out.errors":     net_io_change["errout"],
        "net.out.dropped":    net_io_change["dropout"],
    }

    for name, value in gauges.items():
        gauge.send(name, value)

    time.sleep(1)
