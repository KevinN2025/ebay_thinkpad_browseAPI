{
  description = "ebay_find development environment";

  inputs = {
    nixpkgs.url = "github:NixOS/nixpkgs/nixos-unstable";
    flake-utils.url = "github:numtide/flake-utils";
  };

  outputs = { self, nixpkgs, flake-utils }:
    flake-utils.lib.eachDefaultSystem (system:
      let
        pkgs = nixpkgs.legacyPackages.${system};
      in {
        devShells.default = pkgs.mkShell {
          buildInputs = with pkgs; [
            lazysql
            go
            gnumake
          ];

          shellHook = ''
          mkdir -p "$HOME/.config/lazysql"
            cat > "$HOME/.config/lazysql/config.toml" << 'EOF'
[[database]]
Name = 'Local MariaDB'
Provider = 'mysql'
DBName = 'ebay_find'
URL = 'mysql://KevinN:Eagles7713%40@127.0.0.1:3306/ebay_find'

[application]
DefaultPageSize = 400
DisableSidebar = false
SidebarOverlay = false
JSONViewerWordWrap = false
EnterOpensJSONViewer = false
EOF
            echo "lazysql config written to ~/.config/lazysql/config.toml"
          '';
        };
      }
    );
}
