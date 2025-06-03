FROM debian:bookworm-slim

RUN apt-get update && apt-get install -y \
    git \
    build-essential \
    autoconf \
    redis-server \
    libsqlite3-dev \
    strace \
    bison \
    re2c \
    libssl-dev \
    libcurl4-openssl-dev \
    libonig-dev \
    libxml2-dev \
    pkg-config \
    clangd \
    bear \
    fuse \
    wget \
    ripgrep \
    fzf \
    gdb \
    rr \
    && rm -rf /var/lib/apt/lists/*

ARG PHP_VERSION="8.1.32"
ARG PHPREDIS_BRANCH="6.1.0"
ARG PHPREDIS_URI=https://github.com/phpredis/phpredis.git
ARG PHP_URI="https://www.php.net/distributions/php-${PHP_VERSION}.tar.gz"

RUN cd /usr/src \
    && wget "${PHP_URI}" -O php.tar.gz \
    && tar -xf php.tar.gz \
    && cd php-${PHP_VERSION} \
    && git clone --branch "${PHPREDIS_BRANCH}" --depth 1 \
       "${PHPREDIS_URI}" ext/redis \
    && ./buildconf --force \
    && ./configure --enable-debug --enable-redis \
    && bear -- make -j$(nproc) install

# Neovim w/lsp tools
ARG NEOVIM_VERSION="v0.11.2"
ARG NEOVIM_URI="https://github.com/neovim/neovim/releases/download/${NEOVIM_VERSION}/nvim-linux-x86_64.appimage"
RUN cd /root/ && wget "${NEOVIM_URI}" -O nvim.appimage \
    && chmod u+x nvim.appimage && ./nvim.appimage --appimage-extract \
    && ln -s /root/squashfs-root/usr/bin/nvim /usr/local/bin/nvim \
    && git clone --depth 1 https://github.com/wbthomason/packer.nvim \
       /root/.local/share/nvim/site/pack/packer/start/packer.nvim
COPY init.lua /root/.config/nvim/init.lua
RUN nvim --headless -c 'autocmd User PackerComplete quitall' -c 'PackerSync' \
    && echo "alias vi='nvim'" >> /root/.bashrc

COPY bootstrap.sh monitor.php exec-cmds.php /root/
