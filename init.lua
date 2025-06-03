require('packer').startup(function(use)
    use 'wbthomason/packer.nvim'
    use 'folke/tokyonight.nvim'

    use 'tpope/vim-fugitive'

    -- LSP Stuff
    use 'neovim/nvim-lspconfig'
    use 'hrsh7th/nvim-cmp'
    use 'hrsh7th/cmp-nvim-lsp'
    use 'hrsh7th/cmp-buffer'
    use 'hrsh7th/cmp-path'

    use {
        'junegunn/fzf',
        run = function() vim.fn['fzf#install']() end
    }
    use 'junegunn/fzf.vim'
end)

vim.opt.softtabstop = 4
vim.opt.shiftwidth = 4
vim.opt.expandtab = true
vim.opt.autoindent = true
vim.opt.copyindent = true
vim.opt.smartindent = true
vim.opt.smarttab = true

vim.opt.ignorecase = true
vim.opt.smartcase = true
vim.opt.number = true
vim.opt.relativenumber = true

require'lspconfig'.clangd.setup{
  on_attach = function(client, bufnr)
    -- your on_attach logic (e.g., keymaps)
  end,
  capabilities = require('cmp_nvim_lsp').default_capabilities()
}

require('tokyonight').setup({
    style = 'night',
    transparent = false,
    on_colors = function(colors)
        colors.bg = "#000000"
    end,
    on_highlights = function(hl, c)
        hl.Comment = { fg = "#a9b1d6" }
    end,
})

vim.cmd("colorscheme tokyonight")

vim.opt.termguicolors = true

vim.api.nvim_set_hl(0, "CursorLineNr", { fg = "#fefefe", bold = true })
vim.api.nvim_set_hl(1, "LineNr", { fg = "#5eacd3", bold = true })
