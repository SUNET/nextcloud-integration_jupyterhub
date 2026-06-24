# SPDX-FileCopyrightText: Micke Nordin <kano@sunet.se>
# SPDX-License-Identifier: AGPL-3.0-or-later
#
# Custom JupyterHub image: the stock z2jh hub plus the
# nextcloud-ocm-jupyterhub package. Build from the hub/ directory:
#
#   docker build -f hub/Dockerfile -t <registry>/k8s-hub-ocm:<tag> hub/
#
# Pin BASE_TAG to the k8s-hub tag that matches the JupyterHub Helm chart
# version this deployment installs (chart X.Y.Z ships k8s-hub:X.Y.Z).
ARG BASE_TAG=4.2.0
FROM quay.io/jupyterhub/k8s-hub:${BASE_TAG}

USER root
COPY . /tmp/pkg
RUN pip install --no-cache-dir /tmp/pkg && rm -rf /tmp/pkg
USER 1000
