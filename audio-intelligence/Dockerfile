FROM python:3.11-slim-bookworm

RUN python -m pip install --no-cache-dir \
    essentia-tensorflow==2.1b6.dev1389

WORKDIR /opt/sonotheque
COPY worker.py /opt/sonotheque/worker.py

ENTRYPOINT ["python", "/opt/sonotheque/worker.py"]
