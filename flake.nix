{
  description = "opensearch php dev shell";

  inputs = {
    nixpkgs.url = "github:nixos/nixpkgs/nixos-23.05";
    flake-parts.url = "github:hercules-ci/flake-parts";
    build-tools.url = "git+ssh://gitlab@gitlab.dbc.dk/i-scrum/build-tools.git";
  };
  outputs = inputs@{ flake-parts, nixpkgs, build-tools, ... }:
    flake-parts.lib.mkFlake { inherit inputs; } {
      systems = nixpkgs.lib.systems.flakeExposed ;
      perSystem = { config, pkgs, system, ... }:
      let
        osPHP = pkgs.php.withExtensions ({ enabled, all }: enabled ++ [ all.imagick all.memcached ]);
        test-python-packages = ps: with ps; [
            munch
            pyyaml
            requests
            ruamel-yaml
            lxml
          ];
        test-python = pkgs.python311.withPackages test-python-packages;

        isDarwin = pkgs.lib.hasSuffix "darwin" system;
        darwin-packages = pkgs.lib.lists.optional isDarwin pkgs.darwin.iproute2mac;
        build-docker = build-tools.packages.${system}.default;
      in {
        devShells.default = pkgs.mkShell {
                name = "open search";
                buildInputs = [ osPHP osPHP.packages.composer pkgs.subversion build-docker test-python ]  ++ darwin-packages;
        };
      };
    };
}
